<?php

declare(strict_types=1);

namespace RTCKit\Eqivo\Inbound;

use RTCKit\Eqivo\{
    App,
    CallRequest,
    Conference,
    Core,
    EventEnum,
    HangupCauseEnum,
    Session,
    StatusEnum
};

use React\Http\Message\ResponseException;
use React\Promise\PromiseInterface;
use RTCKit\SIP\Header\NameAddrHeader;
use RTCKit\SIP\Exception\SIPException;
use stdClass as Event;
use function React\Promise\resolve;

class Controller implements ControllerInterface
{
    public App $app;

    public function setApp(App $app): Controller
    {
        $this->app = $app;

        return $this;
    }

    public function onEvent(Core $core, Event $event): void
    {
        if (isset($this->app->inboundServer->handlers[$event->{'Event-Name'}])) {
            $this->app->inboundServer->handlers[$event->{'Event-Name'}]->execute($core, $event);
        }
    }

    public function subscribe(Core $core): PromiseInterface
    {
        return $core->client->event('json ' . implode(' ', [
            EventEnum::BACKGROUND_JOB->value,
            EventEnum::CHANNEL_PROGRESS->value,
            EventEnum::CHANNEL_PROGRESS_MEDIA->value,
            EventEnum::CHANNEL_HANGUP_COMPLETE->value,
            EventEnum::CHANNEL_STATE->value,
            EventEnum::SESSION_HEARTBEAT->value,
            EventEnum::CALL_UPDATE->value,
            EventEnum::RECORD_STOP->value,
            EventEnum::CUSTOM->value . ' conference::maintenance',
        ]));
    }

    /**
     * Fires a HTTP callback (generated by a Session event)
     *
     * @param Session $session
     * @param string $url
     * @param string $method
     * @param array<string, mixed> $params
     *
     * @return PromiseInterface
     */
    public function fireSessionCallback(Session $session, string $url, string $method = 'POST', array $params = []): PromiseInterface
    {
        $params = array_merge($session->getPayload(), $params);

        return $this->app->httpClient->makeRequest($url, $method, $params)
            ->then(function () use ($method, $url, $params): PromiseInterface {
                $this->app->inboundServer->logger->info("Sent to {$method} {$url} with " . json_encode($params));

                return resolve();
            })
            ->otherwise(function (\Throwable $t) {
                $t = $t->getPrevious() ?: $t;

                $this->app->inboundServer->logger->error('Callback failure: ' . $t->getMessage(), [
                    'file' => $t->getFile(),
                    'line' => $t->getLine(),
                ]);
            });
    }

    /**
     * Fires a HTTP callback (generated by a Conference event)
     *
     * @param Conference $conference
     * @param string $url
     * @param string $method
     * @param array<string, mixed> $params
     *
     * @return PromiseInterface
     */
    public function fireConferenceCallback(Conference $conference, string $url, string $method = 'POST', array $params = []): PromiseInterface
    {
        $params = array_merge($conference->getPayload(), $params);

        return $this->app->httpClient->makeRequest($url, $method, $params)
            ->then(function () use ($method, $url, $params): PromiseInterface {
                $this->app->inboundServer->logger->info("Sent to {$method} {$url} with " . json_encode($params));

                return resolve();
            })
            ->otherwise(function (\Throwable $t) {
                $t = $t->getPrevious() ?: $t;

                $this->app->inboundServer->logger->error('Callback failure: ' . $t->getMessage(), [
                    'file' => $t->getFile(),
                    'line' => $t->getLine(),
                ]);
            });
    }

    /**
     * Fires a HTTP callback (generated by a raw event)
     *
     * @param Event $event
     * @param string $url
     * @param string $method
     * @param array<string, mixed> $params
     *
     * @return PromiseInterface
     */
    public function fireEventCallback(Event $event, string $url, string $method = 'POST', array $params = []): PromiseInterface
    {
        foreach ($this->app->config->extraChannelVars as $var) {
            if (isset($event->{$var})) {
                $params[$var] = $event->{$var};
            }
        }

        return $this->app->httpClient->makeRequest($url, $method, $params)
            ->then(function () use ($method, $url, $params): PromiseInterface {
                $this->app->inboundServer->logger->info("Sent to {$method} {$url} with " . json_encode($params));

                return resolve();
            })
            ->otherwise(function (\Throwable $t) {
                $t = $t->getPrevious() ?: $t;

                $this->app->inboundServer->logger->error('Callback failure: ' . $t->getMessage(), [
                    'file' => $t->getFile(),
                    'line' => $t->getLine(),
                ]);
            });
    }

    public function hangupCompleted(Event $event, HangupCauseEnum $reason, ?string $url = null, ?CallRequest $callRequest = null, ?Session $session = null): void
    {
        $calledNum = null;
        $callerNum = null;
        $direction = null;

        $params = [
            'CallUUID' => isset($session) ? $session->uuid : ($event->{'Unique-ID'} ?? ''),
        ];

        if (isset($callRequest)) {
            $callRequest->core->removeCallRequest($callRequest->uuid);

            $this->app->inboundServer->logger->info("Hangup for Outgoing CallUUID {$params['CallUUID']} Completed, HangupCause {$reason->value}, RequestUUID {$callRequest->uuid}");

            $calledNum = ltrim($callRequest->to, '+');
            $callerNum = ltrim($callRequest->from, '+');

            if (isset($callRequest->accountSid)) {
                $accountSid = $callRequest->accountSid;
            }

            $direction = 'outbound';

            $this->app->inboundServer->logger->debug("Call Cleaned up for RequestUUID {$callRequest->uuid}");

            if (isset($callRequest->hangupUrl)) {
                $url = $callRequest->hangupUrl;
            }

            if (!isset($url)) {
                $this->app->inboundServer->logger->debug("No HangupUrl for Outgoing Outgoing Call {$params['CallUUID']}, RequestUUID {$callRequest->uuid}");

                return;
            }

            $aLegRequestUuidVar = "variable_{$this->app->config->appPrefix}_request_uuid";
            $schedHangupIdVar = "variable_{$this->app->config->appPrefix}_sched_hangup_id";
            $params['RequestUUID'] = $callRequest->uuid;

            if (isset($event->variable_sip_h_Diversion)) {
                try {
                    $diversion = NameAddrHeader::parse([$event->variable_sip_h_Diversion]);

                    if (isset($diversion->uri, $diversion->uri->user)) {
                        $params['ForwardedFrom'] = ltrim($diversion->uri->user, '+');
                    }
                } catch (SIPException $e) {
                    $this->app->inboundServer->logger->error("Cannot parse Diversion SIP header '{$event->variable_sip_h_Diversion}'");
                }
            }

            if (isset($event->{'Caller-Unique-ID'}, $event->{'Caller-Unique-ID'}[0])) {
                $params['ALegUUID'] = $event->{'Caller-Unique-ID'};
            }

            if (isset($event->{$aLegRequestUuidVar}, $event->{$aLegRequestUuidVar}[0])) {
                $params['ALegRequestUUID'] = $event->{$aLegRequestUuidVar};
            }

            if (isset($event->{$schedHangupIdVar}, $event->{$schedHangupIdVar}[0])) {
                $params['ScheduledHangupId'] = $event->{$schedHangupIdVar};
            }

            unset($callRequest);
        } else if (isset($session)) {
            $session->core->removeSession($session->uuid);

            $this->app->inboundServer->logger->info("Hangup for Incoming CallUUID {$session->uuid} Completed, HangupCause {$reason->value}");

            $hangupUrlVar = "variable_{$this->app->config->appPrefix}_hangup_url";

            if (isset($event->{$hangupUrlVar})) {
                $url = $event->{$hangupUrlVar};

                $this->app->inboundServer->logger->debug("Using HangupUrl for CallUUID {$session->uuid}");
            } else {
                $answerUrlVar = "variable_{$this->app->config->appPrefix}_answer_url";

                if (isset($this->app->config->defaultHangupUrl)) {
                    $url = $this->app->config->defaultHangupUrl;

                    $this->app->inboundServer->logger->debug("Using HangupUrl from DefaultHangupUrl for CallUUID {$session->uuid}");
                } else if (isset($event->{$answerUrlVar})) {
                    $url = $event->{$answerUrlVar};

                    $this->app->inboundServer->logger->debug("Using HangupUrl from AnswerUrl for CallUUID {$session->uuid}");
                } else if (isset($this->app->config->defaultAnswerUrl)) {
                    $url = $this->app->config->defaultAnswerUrl;

                    $this->app->inboundServer->logger->debug("Using HangupUrl from DefaultAnswerUrl for CallUUID {$session->uuid}");
                }
            }

            if (!isset($url)) {
                $this->app->inboundServer->logger->debug("No HangupUrl for Incoming CallUUID {$session->uuid}");

                return;
            }

            $calledNum = '';
            $calledNumVar = "variable_{$this->app->config->appPrefix}_destination_number";

            if (!isset($event->{$calledNumVar}) || ($event->{$calledNumVar} === '_undef_')) {
                $calledNum = isset($event->{'Caller-Destination-Number'}) ? $event->{'Caller-Destination-Number'} : '';
            } else {
                $calledNum = $event->{$calledNumVar};
            }

            $calledNum = ltrim($calledNum, '+');
            $callerNum = isset($event->{'Caller-Caller-ID-Number'}) ? $event->{'Caller-Caller-ID-Number'} : '';
            $direction = isset($event->{'Call-Direction'}) ? $event->{'Call-Direction'} : '';

            unset($session);
        }

        if (isset($url)) {
            $sipUriVar = "variable_{$this->app->config->appPrefix}_sip_transfer_uri";

            if (isset($event->{$sipUriVar})) {
                $params['SIPTransfer'] = 'true';
                $params['SIPTransferURI'] = $event->{$sipUriVar};
            }

            $params['HangupCause'] = $reason->value;
            $params['To'] = $calledNum;
            $params['From'] = $callerNum ?? '';
            $params['Direction'] = $direction ?? '';
            $params['CallStatus'] = StatusEnum::Completed->value;

            foreach ($this->app->config->extraChannelVars as $var) {
                if (isset($event->{$var})) {
                    $params[$var] = $event->{$var};
                }
            }

            $method = 'POST';

            $this->app->httpClient->makeRequest($url, $method, $params)
                ->then(function () use ($method, $url, $params): PromiseInterface {
                    $this->app->inboundServer->logger->info("Sent to {$method} {$url} with " . json_encode($params));

                    return resolve();
                })
                ->otherwise(function (ResponseException $e) use ($method, $url, $params) {
                    $this->app->inboundServer->logger->info("Sending to {$method} {$url} with " . json_encode($params) . " -- Error: " . $e->getMessage());
                })
                ->otherwise(function (\Throwable $t) {
                    $t = $t->getPrevious() ?: $t;

                    $this->app->inboundServer->logger->error('HangupComplete failure: ' . $t->getMessage(), [
                        'file' => $t->getFile(),
                        'line' => $t->getLine(),
                    ]);
                });
        }
    }
}
