<?php

namespace App\Services\Nobitex;

class SubscriptionManager
{
    /**
     * وضعیت مستقل هر کانال
     */
    private array $subscriptions = [];

    /**
     * ثبت اولیه Subscription
     */
    public function register(
        string $channel,
        ?string $epoch,
        int $offset,
        bool $recoverable,
        bool $positioned,
        bool $recovered = false
    ): void {
        $this->subscriptions[$channel] = [
            'channel' => $channel,
            'epoch' => $epoch,
            'offset' => $offset,
            'recoverable' => $recoverable,
            'positioned' => $positioned,
            'recovered' => $recovered,
        ];
    }

    /**
     * به‌روزرسانی اطلاعات دریافتی از پاسخ Subscribe
     */
    public function updateSubscription(
        string $channel,
        ?string $epoch,
        int $offset,
        bool $recoverable,
        bool $positioned,
        bool $wasRecovering,
        bool $recovered
    ): bool {
        $previous = $this->get($channel);

        // اولین Subscribe
        // Subscribe بدون درخواست Recovery
        if (! $wasRecovering) {
            // اگر موقعیت قبلی نامعتبر شده، تا زمان همگام‌سازی
            // مجدد نباید آن را معتبر اعلام کنیم.
            $keepUnpositioned = $previous !== null
                && ! $previous['positioned'];

            $this->subscriptions[$channel] = [
                'channel' => $channel,
                'epoch' => $epoch,
                'offset' => $offset,
                'recoverable' => $recoverable,
                'positioned' => $keepUnpositioned ? false : $positioned,
                'recovered' => false,
            ];

            return false;
        }

        // Recovery موفق با همان Epoch
        if (
            $recovered &&
            $previous !== null &&
            $epoch !== null &&
            $previous['epoch'] === $epoch &&
            $recoverable &&
            $positioned
        ) {
            $this->subscriptions[$channel] = [
                'channel' => $channel,
                'epoch' => $epoch,
                'offset' => $previous['offset'],
                'recoverable' => $recoverable,
                'positioned' => true,
                'recovered' => true,
            ];

            return true;
        }

        // Recovery ناموفق؛ موقعیت جدید هنوز قابل استفاده نیست.
        $this->subscriptions[$channel] = [
            'channel' => $channel,
            'epoch' => $epoch,
            'offset' => $offset,
            'recoverable' => $recoverable,
            'positioned' => false,
            'recovered' => false,
        ];

        return false;
    }

    /**
     * دریافت وضعیت یک کانال
     */
    public function get(string $channel): ?array
    {
        return $this->subscriptions[$channel] ?? null;
    }

    /**
     * دریافت موقعیت موردنیاز برای Recovery
     */
    public function getRecoveryPosition(string $channel): ?array
    {
        $subscription = $this->get($channel);

        if (
            ! $subscription ||
            ! $subscription['recoverable'] ||
            ! $subscription['positioned'] ||
            ! $subscription['epoch']
        ) {
            return null;
        }

        return [
            'epoch' => $subscription['epoch'],
            'offset' => $subscription['offset'],
        ];
    }

    /**
     * به‌روزرسانی Offset پس از پردازش پیام معتبر
     */
    public function updateOffset(string $channel, int $offset): bool
    {
        if (! isset($this->subscriptions[$channel])) {
            return false;
        }

        if ($offset <= $this->subscriptions[$channel]['offset']) {
            return false;
        }

        $this->subscriptions[$channel]['offset'] = $offset;

        return true;
    }

    /**
     * نامعتبر کردن موقعیت Recovery
     */
    public function invalidate(string $channel): void
    {
        if (! isset($this->subscriptions[$channel])) {
            return;
        }

        $this->subscriptions[$channel]['epoch'] = null;
        $this->subscriptions[$channel]['offset'] = 0;
        $this->subscriptions[$channel]['positioned'] = false;
        $this->subscriptions[$channel]['recovered'] = false;
    }

    /**
     * دریافت وضعیت تمام کانال‌ها
     */
    public function all(): array
    {
        return $this->subscriptions;
    }
}
