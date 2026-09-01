<?php

namespace App\Support;

/**
 * What the <head> says about THIS page — the title, the description and the
 * card image a link unfurls as.
 *
 * Every screen shared the one static answer before this existed: `<title>`
 * and `og:title` both read `$title ?? Brand::name()` and nothing ever passed
 * a `$title`, so a group invite pasted into Slack previewed identically to
 * the front page. The invite IS the acquisition surface, and it was selling
 * the app's tagline rather than the group somebody was being invited to.
 *
 * REQUEST-SCOPED, not static: registered as a scoped binding so one queue
 * worker cannot leak one reader's group name into the next reader's head.
 * A screen sets it in `mount()`, which runs before the layout renders, so
 * the values reach the head on the INITIAL full-page render — the only
 * render a link crawler ever performs. Livewire's `navigate` swaps the head
 * itself and never asks this class again, which is correct: an unfurl is
 * decided by the first HTML response and nothing after it.
 *
 * Every getter falls back to Brand. A screen that says nothing gets exactly
 * what it got before this class existed — the null-means-no-data law: a
 * caller that has no title does not invent one, it declines to set one.
 */
class PageMeta
{
    private ?string $title = null;

    private ?string $description = null;

    private ?string $image = null;

    /**
     * Set what this page is. Every argument is optional and a null argument
     * LEAVES THE SLOT ALONE rather than clearing it, so a caller holding
     * only a description cannot silently blank a title somebody else set.
     */
    public function set(?string $title = null, ?string $description = null, ?string $image = null): void
    {
        $this->title = self::clean($title) ?? $this->title;
        $this->description = self::clean($description) ?? $this->description;
        $this->image = self::clean($image) ?? $this->image;
    }

    public function title(): string
    {
        return $this->title ?? Brand::name();
    }

    /**
     * The window title carries the brand; og:title deliberately does not.
     * A Slack card already prints the site name on its own line, so
     * repeating it inside the headline reads as a stutter — but a browser
     * tab has no such frame and needs the brand to be identifiable.
     */
    public function windowTitle(): string
    {
        return $this->title === null
            ? Brand::name()
            : $this->title.' · '.Brand::name();
    }

    public function description(): string
    {
        return $this->description ?? Brand::tagline();
    }

    public function image(): ?string
    {
        return $this->image ?? Brand::asset('og-image');
    }

    /**
     * Meta content is an HTML attribute, so a newline or a run of spaces
     * from a user-named group has to collapse before it gets there. An
     * empty string is NOT a value — it is a caller with nothing to say,
     * and it falls through to the brand like a null would.
     */
    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : $value;
    }
}
