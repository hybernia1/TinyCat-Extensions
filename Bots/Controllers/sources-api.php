<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

$sourceAction = (string) ($botAdminAction ?? '');

if (in_array($sourceAction, ['run', 'toggle'], true)) {
    api_endpoint('POST', static function () use ($sourceAction): never {
        csrf_require();
        $sourceId = max(1, (int) input('source_id', 0));
        $source = BotAdmin::sourceForAction($sourceId, max(0, (int) input('bot_id', 0)));

        if ($sourceAction === 'toggle') {
            BotAdmin::toggleSource($source);
            $message = t('bots.messages.saved');
        } else {
            $result = BotAdmin::runSource($source);
            $failed = (string) ($result['status'] ?? '') === 'error';
            $message = t($failed ? 'bots.detail_run_failed' : 'bots.detail_run_finished');
        }

        $payload = BotAdmin::payload($sourceId);
        $redirect = auth_safe_next_url((string) input('redirect', ''));
        if ($redirect !== '') {
            flash('success', $message);
            $payload['redirect'] = $redirect;
        }

        api_ok($payload, $message);
    });
}

if (method() === 'GET') {
    api_ok(BotAdmin::payload());
}

if (in_array(method(), ['POST', 'PATCH'], true)) {
    api_endpoint(method(), static function (): never {
        csrf_require();
        $id = method() === 'PATCH' ? max(1, (int) input('id', 0)) : null;

        if ($id !== null && Bots::findSource($id) === null) {
            api_error(t('bots.messages.not_found'), 404, 'bot_source_not_found');
        }

        $payload = BotAdmin::sourcePayload($id);

        try {
            if ($id === null) {
                $id = (int) insert('bot_sources', $payload + ['created_at' => date_db()]);
                $message = t('bots.messages.created');
            } else {
                update('bot_sources', $payload, ['id' => $id]);
                $message = t('bots.messages.saved');
            }
        } catch (Throwable $exception) {
            if (
                Bots::isDuplicateSourceException($exception)
                && Bots::sourceDuplicateExists((string) ($payload['feed_url'] ?? ''), (int) $id)
            ) {
                api_validation(['feed_url' => [t('bots.validation.feed_duplicate')]]);
            }

            throw $exception;
        }

        api_ok(BotAdmin::payload($id), $message);
    });
}

if (method() === 'DELETE') {
    api_endpoint('DELETE', static function (): never {
        csrf_require();
        $id = max(1, (int) input('id', 0));

        if (Bots::findSource($id) === null) {
            api_error(t('bots.messages.not_found'), 404, 'bot_source_not_found');
        }

        Bots::deleteSource($id);
        api_ok(BotAdmin::payload(), t('bots.messages.deleted'));
    });
}

api_error('Method not allowed.', 405, 'method_not_allowed');
