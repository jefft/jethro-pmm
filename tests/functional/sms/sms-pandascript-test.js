// PandaScript: SMS sender dropdown — tests server-rendered content
// JS-dependent interactions (dropdown/modal) are tested via evaluate.
//
// Requires a FrankenPHP test server with SMS_SENDER_OPTIONS=Foo,Bar.
// Run 'make test-servers-up' first; this test hits the sms-sender-options
// instance on port 9012 (see tests/functional/test-servers.sh).
var BASE = "http://127.0.0.1:9012";

var page = new Page();

// ── Login ──
await page.goto(BASE + "/");
await page.waitForSelector("input[name=username]");
page.fill("input[name=username]", "demo");
page.fill("input[name=password]", "qfntt7eYuwHs123");
page.click("input[value='Log In']");
await page.waitForSelector("h1");

// ── Navigate to Martin Luther's person page ──
await page.goto(BASE + "/?view=persons&personid=4");
await page.waitForSelector("#mobile-4");

// ── Test 1: Page metadata ──
var title = page.evaluate("document.title");
var hasMobile = page.evaluate("!!document.querySelector('#mobile-4')");

// ── Test 2: SMS modal exists in DOM (server-rendered, hidden) ──
var modalExists = page.evaluate("!!document.querySelector('#send-sms-modal')");

// ── Test 3: Sender options in the dropdown ──
var options = page.extract([{
  selector: "#sms_sender option",
  fields: {
    value: { attr: "value" },
    text: {},
    needsReg: { attr: "data-needs-registration" }
  }
}]);

// ── Test 4: Open modal programmatically and verify ──
page.evaluate(
  "var modal = document.querySelector('#send-sms-modal');"
+ "if (modal) { modal.classList.remove('hide'); modal.classList.add('in'); }"
);
var visibleOptions = page.extract([{
  selector: "#sms_sender option",
  fields: { value: { attr: "value" }, text: {} }
}]);

return {
  page_title: title,
  mobile_link_found: hasMobile,
  modal_in_dom: modalExists,
  sender_options: options.map(function(o) { return {
    value: o.value,
    text: o.text,
    needs_registration: o.needsReg === "1"
  }; }),
  option_count: options.length,
};
