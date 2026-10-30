<?php

namespace App\Services;

use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps exactly one default address per user. Every write locks the user's row first,
 * so two concurrent requests for the same user run one after the other
 * (locking address rows would not protect the "first address" case: there are none yet).
 */
class AddressService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): Address
    {
        return DB::transaction(function () use ($user, $data) {
            $this->lock($user);

            $makeDefault = ! $user->addresses()->exists() || ($data['is_default'] ?? false) === true;

            if ($makeDefault) {
                $this->clearDefault($user);
            }

            return $user->addresses()->create([...$data, 'is_default' => $makeDefault]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Address $address, array $data): Address
    {
        return DB::transaction(function () use ($address, $data) {
            $user = $address->user;
            $this->lock($user);
            $address->refresh();

            $wantsDefault = $data['is_default'] ?? null;
            unset($data['is_default']);

            // Only promoting a different address can change the default; "false" on the default is ignored.
            if ($wantsDefault === true && ! $address->is_default) {
                $this->clearDefault($user);
                $address->is_default = true;
            }

            $address->fill($data)->save();

            return $address;
        });
    }

    public function delete(Address $address): void
    {
        DB::transaction(function () use ($address) {
            $user = $address->user;
            $this->lock($user);
            $address->refresh();

            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {
                $user->addresses()->orderByDesc('id')->first()?->update(['is_default' => true]);
            }
        });
    }

    private function lock(User $user): void
    {
        User::whereKey($user->id)->lockForUpdate()->first();
    }

    private function clearDefault(User $user): void
    {
        $user->addresses()->where('is_default', true)->update(['is_default' => false]);
    }
}
