 # Person-chooser autosuggest: clicking a suggestion only works if it was already highlighted
 
 **Verdict: real bug — a click can silently do nothing, or select the wrong
 person after the suggestions list is rebuilt.**
 
+**Status: fixed** — the anchor's `onclick` now highlights the clicked item
+before committing (`pointer.setHighlight(this.name); pointer.setHighlightedValue();`),
+and `setHighlightedValue()` guards against a stale index
+(`if (this.iHigh && this.aSug[this.iHigh-1])`). A click always commits exactly
+the item clicked, and the mouseover-before-click ordering no longer matters.
+See "Fix applied" below.
+
 ## What happens
 
 `resources/js/bsn_autosuggest.js` builds the person search suggestions:
 
 ```js
 a.onclick     = function () { pointer.setHighlightedValue(); return false; }; // line 256
 a.onmouseover = function () { pointer.setHighlight(this.name); };             // line 257
 ```
 
 and `setHighlightedValue()` commits only when something is highlighted:
 
 ```js
 // line 341
 if (this.iHigh) {           // 0 = nothing highlighted → whole body skipped
     this.fld.value = this.aSug[this.iHigh-1].value;
     ...
     this.oP.callback(this.aSug[this.iHigh-1]);
 }
 ```
 
 So a click on an item that was never highlighted (no preceding `mouseover`,
 no arrow-key move) **silently does nothing**. On top of that, the suggestions
 list is rebuilt on every keystroke and auto-clears ~1 s after typing stops
 (`resetTimeout`, line 364); if the list is rebuilt — or a new AJAX response
 lands — between the user's mouseover and the click, `iHigh` points at a stale
 or different item and the click commits the wrong person (or nothing).
 
 ## Why the current behaviour is bad
 
 - **Silent no-op.** No error, no feedback — the selection just doesn't happen.
 - **Wrong-person risk.** In a church database, committing the wrong person
   attaches notes, roster assignments and user accounts to the wrong record —
   a data-integrity hazard, not just a nuisance.
 - **Breaks real users.** Keyboard users (or anyone clicking fast) can hit the
   no-op case; anyone who types, waits for the list, then clicks after a pause
   hits the stale-list case.
 - **Racy for automation.** The functional suite's Playwright tests could not
   click reliably and had to switch to ArrowDown + Enter; anything driving the
   widget programmatically (`.click()`) never works at all, because no
   `mouseover` is synthesized.
 
 ## Suggested improvement
 
 Make the click self-contained — highlight the clicked item, then commit it:
 
 ```js
 a.onclick = function () {
     pointer.setHighlight(this.name);   // highlight the item that was clicked
     pointer.setHighlightedValue();
     return false;
 };
 ```
 
 Also guard the commit against a stale index:
 
 ```js
 if (this.iHigh && this.aSug[this.iHigh-1]) { ... }
 ```
 
 With these two changes the click always commits exactly the item clicked, and
 the mouseover-before-click ordering no longer matters.
 
+## Fix applied
+
+`resources/js/bsn_autosuggest.js`:
+
+```diff
+ // createList(), per suggestion anchor
+-a.onclick = function () { pointer.setHighlightedValue(); return false; };
++a.onclick = function () { pointer.setHighlight(this.name); pointer.setHighlightedValue(); return false; };
+
+ // setHighlightedValue()
+-if (this.iHigh)
++if (this.iHigh && this.aSug[this.iHigh-1])
+```
+
+`setHighlight(this.name)` sets `iHigh` from the clicked anchor's own 1-based
+index (`a.name = i+1`, valid for the list the anchor currently lives in), so
+the commit targets exactly the item clicked; the extra `this.aSug[...]` check
+means a stale index (list rebuilt between hover and click) degrades to a
+no-op instead of committing a wrong person or crashing.
+
+Verified against the live instance:
+
+- programmatic click on a suggestion with no prior mouseover → previously a
+  silent no-op (`personid` stayed `0`); now selects correctly (`personid`
+  set, input shows "Name (#id)")
+- real mouse click → still selects
+- no JS errors on either path
+
 ## Where
 
 - `resources/js/bsn_autosuggest.js`
   - line 256-257 — anchor `onclick` / `onmouseover` bindings
   - line 341-359 — `setHighlightedValue()` (the `if (this.iHigh)` guard)
   - line 364-369 — `resetTimeout()` (auto-clear that can invalidate a hover)
%%%%%%% diff from: pwmzkssx 08957c9c "Func tests: add 'lookaround' and 'walkthrough' real tests" (parents of squashed revision)
\\\\\\\        to: selected changes for squash (from uoupxmoq 0139da9e "jturner development tools")
+# Person-chooser autosuggest: clicking a suggestion only works if it was already highlighted
+
+**Verdict: real bug — a click can silently do nothing, or select the wrong
+person after the suggestions list is rebuilt.**
+
+## What happens
+
+`resources/js/bsn_autosuggest.js` builds the person search suggestions:
+
+```js
+a.onclick     = function () { pointer.setHighlightedValue(); return false; }; // line 256
+a.onmouseover = function () { pointer.setHighlight(this.name); };             // line 257
+```
+
+and `setHighlightedValue()` commits only when something is highlighted:
+
+```js
+// line 341
+if (this.iHigh) {           // 0 = nothing highlighted → whole body skipped
+    this.fld.value = this.aSug[this.iHigh-1].value;
+    ...
+    this.oP.callback(this.aSug[this.iHigh-1]);
+}
+```
+
+So a click on an item that was never highlighted (no preceding `mouseover`,
+no arrow-key move) **silently does nothing**. On top of that, the suggestions
+list is rebuilt on every keystroke and auto-clears ~1 s after typing stops
+(`resetTimeout`, line 364); if the list is rebuilt — or a new AJAX response
+lands — between the user's mouseover and the click, `iHigh` points at a stale
+or different item and the click commits the wrong person (or nothing).
+
+## Why the current behaviour is bad
+
+- **Silent no-op.** No error, no feedback — the selection just doesn't happen.
+- **Wrong-person risk.** In a church database, committing the wrong person
+  attaches notes, roster assignments and user accounts to the wrong record —
+  a data-integrity hazard, not just a nuisance.
+- **Breaks real users.** Keyboard users (or anyone clicking fast) can hit the
+  no-op case; anyone who types, waits for the list, then clicks after a pause
+  hits the stale-list case.
+- **Racy for automation.** The functional suite's Playwright tests could not
+  click reliably and had to switch to ArrowDown + Enter; anything driving the
+  widget programmatically (`.click()`) never works at all, because no
+  `mouseover` is synthesized.
+
+## Suggested improvement
+
+Make the click self-contained — highlight the clicked item, then commit it:
+
+```js
+a.onclick = function () {
+    pointer.setHighlight(this.name);   // highlight the item that was clicked
+    pointer.setHighlightedValue();
+    return false;
+};
+```
+
+Also guard the commit against a stale index:
+
+```js
+if (this.iHigh && this.aSug[this.iHigh-1]) { ... }
+```
+
+With these two changes the click always commits exactly the item clicked, and
+the mouseover-before-click ordering no longer matters.
+
+## Where
+
+- `resources/js/bsn_autosuggest.js`
+  - line 256-257 — anchor `onclick` / `onmouseover` bindings
+  - line 341-359 — `setHighlightedValue()` (the `if (this.iHigh)` guard)
+  - line 364-369 — `resetTimeout()` (auto-clear that can invalidate a hover)
