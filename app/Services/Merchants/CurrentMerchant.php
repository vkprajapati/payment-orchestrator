<?php

namespace App\Services\Merchants;

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CurrentMerchant
{
    /**
     * The session key under which the current merchant ID is stored.
     */
    public const SESSION_KEY = 'current_merchant_id';

    /**
     * The merchant resolved for the current request, if any.
     */
    protected ?Merchant $resolved = null;

    /**
     * Whether the current merchant has already been resolved this request.
     */
    protected bool $hasResolved = false;

    public function __construct(
        protected SessionManager $session,
        protected AuthFactory $auth,
    ) {}

    /**
     * Resolve the currently active merchant for the authenticated user.
     *
     * The merchant ID stored in the session is never trusted blindly: it is
     * always validated against the authenticated user's merchant membership.
     * If the stored ID is invalid, it is cleared and a default merchant is
     * selected based on the oldest membership.
     */
    public function get(): ?Merchant
    {
        if ($this->hasResolved) {
            return $this->resolved;
        }

        $this->hasResolved = true;

        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            return $this->resolved = null;
        }

        $merchantId = $this->session->get(self::SESSION_KEY);

        if (is_numeric($merchantId)) {
            $merchant = $user->merchants()
                ->where('merchants.id', (int) $merchantId)
                ->first();

            if ($merchant !== null) {
                return $this->resolved = $merchant;
            }

            $this->session->forget(self::SESSION_KEY);
        }

        return $this->resolved = $this->resolveDefaultFor($user);
    }

    /**
     * Set the current merchant for the authenticated user.
     *
     * @throws HttpException
     */
    public function set(Merchant $merchant): void
    {
        $user = $this->auth->guard()->user();

        abort_unless(
            $user instanceof User && $user->merchants()->where('merchants.id', $merchant->id)->exists(),
            403,
            'The authenticated user does not belong to this merchant.',
        );

        $this->session->put(self::SESSION_KEY, $merchant->id);

        $this->reset();
    }

    /**
     * Clear the current merchant selection from the session.
     */
    public function clear(): void
    {
        $this->session->forget(self::SESSION_KEY);

        $this->reset();
    }

    /**
     * Determine whether a valid current merchant context is available.
     */
    public function has(): bool
    {
        return $this->get() !== null;
    }

    /**
     * Get the ID of the currently active merchant, if any.
     */
    public function id(): ?int
    {
        return $this->get()?->id;
    }

    /**
     * Get the authenticated user's role within the current merchant, if any.
     */
    public function role(): ?string
    {
        return $this->get()?->pivot?->role;
    }

    /**
     * Select the user's primary merchant based on the oldest membership,
     * falling back to the lowest merchant ID to break ties.
     */
    protected function resolveDefaultFor(User $user): ?Merchant
    {
        $merchant = $user->merchants()
            ->orderBy('merchant_user.created_at')
            ->orderBy('merchants.id')
            ->first();

        if ($merchant !== null) {
            $this->session->put(self::SESSION_KEY, $merchant->id);
        }

        return $merchant;
    }

    /**
     * Discard any resolved merchant so the next call re-reads from the session.
     */
    protected function reset(): void
    {
        $this->resolved = null;
        $this->hasResolved = false;
    }
}
