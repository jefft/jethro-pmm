# Bulk person update: GET access shows empty/hidden page with a PHP warning

**Verdict: real bug — the page silently renders nothing useful (the `#body`
div is `hidden`) when accessed without POSTed person IDs, instead of
showing an error or redirecting.**

## What happens

`views/view_0_persons_bulk_update.class.php` expects `$_POST['personid']`
to be set (a user must check persons on a list page, then click a "Bulk
Update" link that submits the form):

```php
// view_0_persons_bulk_update.class.php, lines 14-16
function processView()
{
    if (empty($_POST['personid'])) {
        trigger_error("Cannot update persons, no person ID specified", E_USER_WARNING);
        return;
    }
    // ...
}
```

When the page is accessed directly via GET (e.g.
`?view=_persons_bulk_update`), or by re-navigating to the URL after the
initial POST redirect, `$_POST['personid']` is empty and the method raises
a PHP `E_USER_WARNING` then returns without printing anything. The page
renders with the `#body` div hidden (CSS `display: none`) and the user
sees either a blank page or just the nav bar — no explanation.

The functional test suite (`tests/functional/tests/walkthrough.spec.ts`)
hit this: Playwright's `expect(page.locator("#body")).toBeVisible()`
failed because the div was hidden.

## Why the current behaviour is bad

- **Undiscoverable failure.** The page appears to render, but the content
  area is empty. There's no "You must select persons first" message, no
  redirect to the person list, no indication of what went wrong.
- **Breaks direct navigation and bookmarks.** A user who bookmarks the
  URL or hits back/forward in their browser lands on a blank page.
- **No visible error.** `E_USER_WARNING` sends a warning to the PHP error
  log (or is suppressed in production). The user sees nothing.

## Suggested improvement

Instead of `trigger_error` and returning, print a user-facing message and
offer a way forward:

```php
if (empty($_POST['personid'])) {
    add_message("Please select one or more persons before using Bulk Update", 'error');
    // No return — let printView() show the message, or redirect.
}
```

Even simpler: redirect to the person list with a message, since there is
nothing meaningful to render without selected persons:

```php
if (empty($_POST['personid'])) {
    add_message("Please select one or more persons before using Bulk Update", 'error');
    redirect('persons__list_all');
    return;
}
```

## Where

- `views/view_0_persons_bulk_update.class.php`, lines 14-17 — the guard
  clause with `trigger_error`
- `views/view_0_groups_bulk_update.class.php` — same pattern; check if
  it has the same issue
