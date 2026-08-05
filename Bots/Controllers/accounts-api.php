<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

if (method() === 'GET') {
    api_ok(BotAdmin::accountsPayload());
}

if (method() === 'POST') {
    api_endpoint('POST', static function (): never {
        csrf_require();
        $id = (int) insert('users', BotAdmin::accountCreatePayload());
        api_created(BotAdmin::accountsPayload($id), t('bots.messages.account_created'));
    });
}

if (method() === 'PATCH') {
    api_endpoint('PATCH', static function (): never {
        csrf_require();
        $id = max(1, (int) input('id', 0));
        $account = BotAdmin::accountById($id);

        if ($account === null) {
            api_error(t('bots.messages.account_not_found'), 404, 'bot_account_not_found');
        }

        $status = (string) input('status', '');
        if (!array_key_exists($status, admin_user_statuses())) {
            api_validation(['status' => [t('users.validation.status_invalid')]]);
        }

        try {
            $avatar = admin_user_avatar_change($account);
        } catch (InvalidArgumentException $exception) {
            api_error($exception->getMessage(), 422, 'avatar_invalid');
        }

        $payload = [
            'status' => $status,
            'bio' => plain_text_limit((string) input('bio', ''), 500),
        ];
        if ($avatar['changed']) {
            $payload['avatar_config'] = $avatar['json'];
        }

        try {
            db_transaction(static function () use ($payload, $id, $status): void {
                update('users', $payload, ['id' => $id]);
                if ($status !== 'active') {
                    update('bot_sources', ['enabled' => 0], ['bot_user_id' => $id]);
                }
            });
        } catch (Throwable $exception) {
            if ($avatar['uploaded']) {
                Avatar::delete($avatar['config']);
            }
            throw $exception;
        }

        if ($avatar['changed']) {
            Avatar::delete($account['avatar_config'] ?? null, $avatar['config']);
        }

        $response = BotAdmin::accountsPayload($id);
        flash('success', t('bots.messages.saved'));
        $response['redirect'] = '/admin/bots/' . $id;
        api_ok($response, t('bots.messages.saved'));
    });
}

if (method() === 'DELETE') {
    api_endpoint('DELETE', static function (): never {
        csrf_require();
        $id = max(1, (int) input('id', 0));
        $account = BotAdmin::accountById($id);

        if ($account === null) {
            api_error(t('bots.messages.account_not_found'), 404, 'bot_account_not_found');
        }

        user_delete_account($id);
        api_ok(BotAdmin::accountsPayload(), t('bots.messages.account_deleted'));
    });
}

api_error('Method not allowed.', 405, 'method_not_allowed');
