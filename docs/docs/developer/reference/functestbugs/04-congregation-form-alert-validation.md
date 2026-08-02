# Congregation form: native alert() cancels submission with no inline feedback

**Verdict: intentional validation, poor implementation — worth fixing.**

## What happens

The congregation edit form's submit handler validates two states with native
`alert()` dialogs:

```js
// resources/js/jethro.js, lines 653-682
congForm.submit(function() {
    if ($('input[name=holds_attendance]').attr('checked')) {
        var gotChecked = false;
        $('#field-attendance_recording_days input[type=checkbox]').each(function() {
            if (this.checked) gotChecked = true;
        });
        if (!gotChecked) {
            alert('If attendance is enabled, you must choose at least one day to record attendance');
            return false;                       // ← submission cancelled here
        }
    } else {
        // clear all days silently
    }
    if ($('input[name=holds_services]').attr('checked')) {
        if ($('input[name=meeting_time]').val() == '') {
            alert('If services are enabled, you must enter a time code');
            return false;
        }
    }
});
```

`alert()` blocks the script; the submit is cancelled and the only feedback is
the native dialog. In headless browsers and automation the dialog is dismissed
automatically and the cancellation is **invisible** — the form just doesn't
save.

## Why the current behaviour is bad

- **Silent failure in automation/headless.** No way to detect why the save
  didn't happen; the functional suite hit exactly this while configuring the
  "None" and "External Supporters" congregations.
- **Blocks a legitimate configuration.** The reference instance's "None" and
  "External Supporters" congregations hold persons but record **no
  attendance**. The form makes that state unreachable while "Attendance can be
  recorded" is ticked, and the alert gives no hint that the toggle is the
  problem — the user must already know to untick it.
- **Inconsistent UX.** Everywhere else Jethro validates inline
  (`markErroredInput` with a message under the field); here it uses a jarring
  native dialog.

## Suggested improvement

1. Replace both `alert()` calls with the app's inline validation pattern —
   highlight the offending field(s) and print a message next to them (TBLib
   `markErroredInput`), so the reason is visible in-page and detectable in
   automation.
2. Friendlier behaviour for the attendance-less case: when the last
   attendance-day checkbox is unticked, auto-untick "Attendance can be
   recorded" (mirroring the existing `else` branch that clears the days when
   the toggle is turned off). Then a persons-only congregation saves without
   any error at all.

## Where

- `resources/js/jethro.js`, lines 653-682 — `congForm.submit` handler with the
  two `alert()` calls and the `holds_attendance` / `holds_services` toggle
  logic
