# Ash Salesforce

Sends webform and contact form submissions to Salesforce as Cases or Leads,
through the Web-to-Case or Web-to-Lead form the client's Salesforce admin
generates. No connected app, no API login.

## How it works

1. **Paste the generated form** at *Configuration → Web services → Salesforce
   form mappings → Salesforce forms*. The HTML Salesforce generates is the
   whole description of the org: endpoint, org ID, field IDs, max lengths and
   picklist values. The page shows what it read, so a bad paste is obvious.
2. **Add a mapping.** Pick the site form and the Salesforce form, then walk
   through the site form one field at a time and click the Salesforce field it
   goes to. The likeliest target is offered first, outlined and marked
   *Suggested*; *Don't send* keeps a field on the site only. Obvious non-data
   (buttons, markup, CAPTCHAs, elements hidden from the form) is skipped.
3. **Set fixed values**: the record type that routes the record, an origin
   that says which site and form it came from. Picklists are stored by option
   label, not by ID.
4. **Review and save.** Edit opens on the summary; any one choice can be
   changed without walking the form again.

Submissions are queued on save, sent at the end of the same request, and
retried by cron if Salesforce is down. A 4xx is logged and dropped.

## Origin: which site, which form

One org usually takes records from several sites, and from several forms on
each. So a new mapping suggests Case Origin (or Lead Source) as
`<site label> <form label>`, e.g. *Metro Contact the MAC*, editable before
saving. Set the site label under *Settings*; empty uses the site name.

The client's admin has to add each value to the Origin picklist and enable it
for the record type, or a restricted picklist drops the record silently.

## One mapping, several orgs

Custom field and picklist IDs differ between a sandbox and production. Paste
each org's form as its own Salesforce form. Mappings key everything by stable
names (the standard field name, or a custom field's label), so pointing an
environment at another org is one override in `settings.php`:

```php
if (($_ENV['PANTHEON_ENVIRONMENT'] ?? '') === 'live') {
  $config['ash_salesforce.mapping.contact']['salesforce_form'] = 'prod';
}
```

To keep an environment from sending at all:

```php
$config['ash_salesforce.mapping.contact']['status'] = FALSE;
```

## Things to know

- **Web-to-Case answers 200 even when it drops a record.** The reason goes only
  to the debug email in the client's Web-to-Case settings, so ask them to keep
  one. Values are cut to the field's max length before sending, because
  Salesforce rejects an overrun the same silent way.
- **Turn off Web-to-Case's own reCAPTCHA.** Submissions are posted
  server-side and can't solve it; guard the site form with its own CAPTCHA.
- **Assignment and auto-response rules** run as they do for any Web-to-Case
  record.
- **Contact forms are sent on submit, not on save.** Without contact_storage a
  message is never stored, so there is no insert to hook.
- **Webform drafts** are sent once, when completed.

## Tests

```
vendor/bin/phpunit -c /var/www/html/phpunit.xml web/modules/contrib/ash_salesforce/tests/src
```

Tagged `aai` and `ash_salesforce`. Salesforce is mocked; nothing is posted.
