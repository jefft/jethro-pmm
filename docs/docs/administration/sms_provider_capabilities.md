{/*
Prompt to regenerate this file when provider capabilities change:

For each non-deprecated, non-abstract SmsProvider implementation —
FiveCentSmsV5Provider, FiveCentSmsV4Provider, CellcastSmsProvider
— read its getCapabilities() return value.
Omit TemplateSmsProvider, decorators, and deprecated providers from
the table. Map each SmsCapability enum case to Title Case (e.g.
GET_BALANCE → "Get Balance") and pair it with its human-readable
description from the enum's docblock or a short summary of the
interface method it gates. Produce a single HTML table with one
row per capability: bold Title Case name on the first line,
description on the second line (using <br />). Use ✅ for supported
and — for unsupported. Columns: Capability, 5Cent v5, 5Cent v4,
Cellcast.
*/}

### SMS Provider Capability Matrix

<table>
  <thead>
    <tr>
      <th>Capability</th>
      <th>5Cent v5</th>
      <th>5Cent v4</th>
      <th>Cellcast</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td><strong>Get Balance</strong><br />Query the account balance (remaining SMS credits).</td>
      <td>✅</td>
      <td>✅</td>
      <td>✅</td>
</tr>
    <tr>
      <td><strong>Get Sender IDs</strong><br />List registered sender IDs, including ACMA approval status.</td>
      <td>✅</td>
      <td>—</td>
      <td>—</td>
</tr>
    <tr>
      <td><strong>Deferred Send</strong><br />Schedule a message for future delivery via a <code>sendAt</code> timestamp.</td>
      <td>✅</td>
      <td>—</td>
      <td>✅</td>
</tr>
    <tr>
      <td><strong>Deferred Send Cancel</strong><br />Cancel a previously scheduled message before delivery.</td>
      <td>✅</td>
      <td>—</td>
      <td>✅</td>
</tr>
    <tr>
      <td><strong>Register Sender Number</strong><br />Register a phone number as a sender. Verification may be via OTP or out-of-band link.</td>
      <td>✅</td>
      <td>—</td>
      <td>✅</td>
</tr>
    <tr>
      <td><strong>Register Sender ID</strong><br />Register a sender ID (business identity) with the upstream gateway.</td>
      <td>✅</td>
      <td>—</td>
      <td>✅</td>
    </tr>
    <tr>
      <td><strong>Import History</strong><br />Import / sync SMS history.</td>
      <td>✅ partial<br/> - senders not imported</td>
      <td>—</td>
      <td>✅</td>
    </tr>
  </tbody>
</table>
