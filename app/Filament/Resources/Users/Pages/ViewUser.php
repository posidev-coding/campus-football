<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\DeleteUser;
use App\Actions\ImpersonateUser;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Users\Widgets\UserStats;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Support\Htmlable;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The store-detail heading: avatar or initials, the name, status badges
     * and a row of icon'd facts.
     *
     * A View is Htmlable, which `getHeading()` accepts — so this is the shared
     * partial rather than a header bespoke to this one resource.
     */
    public function getHeading(): Htmlable
    {
        $record = $this->getRecord();

        return view('filament.partials.record-heading', [
            'image' => $record->avatarUrl(),
            'initials' => $record->initials(),
            'title' => $record->name,
            'badges' => array_values(array_filter([
                $record->isAdmin() ? ['label' => 'Admin', 'color' => 'warning'] : null,
                $record->hasVerifiedEmail()
                    ? ['label' => 'Verified', 'color' => 'success']
                    : ['label' => 'Unverified', 'color' => 'danger'],
            ])),
            'meta' => array_values(array_filter([
                $record->handle ? ['icon' => 'heroicon-o-at-symbol', 'label' => $record->handle] : null,
                ['icon' => 'heroicon-o-envelope', 'label' => $record->email],
                ['icon' => 'heroicon-o-calendar', 'label' => 'Joined '.$record->created_at?->format('M j, Y')],
            ])),
        ]);
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            UserStats::make(['record' => $this->getRecord()]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            $this->verifyEmail(),
            $this->toggleAdmin(),
            $this->impersonate(),
            $this->delete(),
        ];
    }

    /**
     * Verify an email by hand, for the reader whose verification mail went to
     * a spam folder nobody can reach.
     *
     * The event is dispatched HERE, deliberately and explicitly.
     * `markEmailAsVerified()` is the framework's trait method and it only
     * writes the column — `Verified` is fired by VerifyEmailController, which
     * is what makes the reward listener the doorway rather than a coincidence.
     * Calling the trait method alone would mark somebody verified and quietly
     * skip the 100 XP and the Beast Latte, which is the failure this whole
     * action exists to avoid. Same two steps, same order, as the controller.
     *
     * The grant is keyed, so pressing this against an account that once
     * verified before pays nothing a second time.
     */
    private function verifyEmail(): Action
    {
        return Action::make('verifyEmail')
            ->label('Verify email')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (User $record): bool => ! $record->hasVerifiedEmail())
            ->requiresConfirmation()
            ->modalDescription('Marks the address verified, which pays the usual verification reward — 100 XP and a Beast Latte. The grant is keyed, so it can only ever pay once.')
            ->action(function (User $record): void {
                if ($record->markEmailAsVerified()) {
                    event(new Verified($record));
                }

                Notification::make()->success()->title('Email verified')->send();
            });
    }

    /**
     * Grant or revoke admin.
     *
     * `forceFill`, because `admin` is deliberately outside `#[Fillable]` — it
     * is a privilege escalation vector the moment it reaches a mass-assignment
     * path from a request, and this is the only sanctioned write.
     *
     * Hidden on yourself: an admin demoting themselves loses the panel and
     * cannot get back in through it.
     */
    private function toggleAdmin(): Action
    {
        return Action::make('toggleAdmin')
            ->label(fn (User $record): string => $record->isAdmin() ? 'Revoke admin' : 'Make admin')
            ->icon(Heroicon::OutlinedKey)
            ->color(fn (User $record): string => $record->isAdmin() ? 'danger' : 'warning')
            ->hidden(fn (User $record): bool => $record->is(auth()->user()))
            ->requiresConfirmation()
            ->modalDescription(fn (User $record): string => $record->isAdmin()
                ? 'They lose the admin panel and every power in it immediately.'
                : 'They get the admin panel and every power in it, including this one.')
            ->action(function (User $record): void {
                $record->forceFill(['admin' => ! $record->admin])->save();

                Notification::make()->success()
                    ->title($record->admin ? 'Now an admin' : 'No longer an admin')
                    ->send();
            });
    }

    /**
     * Sign in as this person, to see exactly what they see.
     *
     * The guards live in the Action class, not here — this visibility check is
     * so the button does not offer something that would 403, and the Action
     * refuses it again for anything that reaches it another way.
     */
    private function impersonate(): Action
    {
        return Action::make('impersonate')
            ->label('Sign in as')
            ->icon(Heroicon::OutlinedArrowRightEndOnRectangle)
            ->color('gray')
            ->visible(fn (User $record): bool => ! $record->isAdmin() && ! $record->is(auth()->user()))
            ->requiresConfirmation()
            ->modalDescription('You will be signed in as them until you press Return to admin on the amber bar at the top of the app. Anything you do counts as them doing it.')
            ->action(function (User $record) {
                app(ImpersonateUser::class)->handle(auth()->user(), $record);

                // navigate: false — this crosses out of the panel and into the
                // product's own asset bundle.
                $this->redirect(route('home'), navigate: false);
            });
    }

    /**
     * Delete the account and everything that only existed because of it.
     *
     * The modal enumerates the cascade rather than saying "this cannot be
     * undone", because the sentence people actually need is which of their
     * things go with them.
     */
    private function delete(): Action
    {
        return Action::make('delete')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->hidden(fn (User $record): bool => $record->is(auth()->user()))
            ->requiresConfirmation()
            ->modalHeading('Delete this account')
            ->modalDescription('Their picks, slate entries, group memberships, followed teams, wallet ledger, conversation posts, notifications and push devices all go with them. Contests and slates they played in are untouched.')
            ->action(function (User $record) {
                if (! app(DeleteUser::class)->handle(auth()->user(), $record)) {
                    Notification::make()->danger()
                        ->title('You cannot delete your own account from here')
                        ->send();

                    return null;
                }

                Notification::make()->success()->title('Account deleted')->send();

                return $this->redirect(UserResource::getUrl('index'));
            });
    }
}
