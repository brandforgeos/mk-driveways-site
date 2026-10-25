# Hotfixes applied to the compiled build (not yet in the source backup)

Re-apply these in the hub source before the next full build, or they will be lost.

## 2026-10-22 — Today screen: replies + job stage on quotes
Today screen (component with "Quotes this week"):
1. `quote_pipeline` select now also reads `customer_id, job_status` (DB view gained `job_status`).
2. New query `replies_to_answer` (DB view: one row per unread inbound thread, last 30 days, company-scoped),
   rendered as a "Replies to answer" section above "In the diary today". Text rows link to `/inbox/<number>`;
   email rows link to the job's History and call `mark_thread_read`.
3. Quote rows: once accepted, pill/sub follow the job stage (Booked / On site / Done / Invoiced / Paid / Cancelled).
   Viewed quotes whose customer has an unread reply show "Replied".

DB (applied live): `private.e164()`; customers/blocked_numbers phone normalised to +44 on write;
`link_message_customer` normalises SMS numbers, matches customer (email too) and attaches inbound replies to the job
(`private.reply_job`: last job messaged, else latest live job); `link_call_customer` normalises `from_number`;
existing rows backfilled.

## 2026-10-25 — Send for signature (customer e-signing)
Added as standalone pages + an injected helper, so nothing in the React bundle changed:
- `sign/index.html` — the customer's signing page (`/sign/?t=<public_token>`): company-branded, shows the PDF (pdf.js),
  "Sign here" marker, typed full name + drawn signature + consent tick, then a download of the signed copy.
- `sign/new/index.html` — director page (`/sign/new/?job=<job id>`): upload a PDF, tap where the signature goes,
  send by email/text (RPC `send_sign_request`), list/copy/resend/cancel existing requests for the job.
- `sign/lib/` — self-hosted pdf.js 4.10.38 and supabase-js 2.45.4 UMD (no third-party CDN at runtime).
- `sign-share.js` (loaded from index.html) — "Send for signature" button on a job page, directors only.
- `sw.js` — NavigationRoute now has `denylist: [/^\/sign\//]` so the PWA doesn't swallow /sign/ links; index.html
  revision bumped; sign-share.js precached. `.cpanel.yml` copies `sign/` and `sign-share.js`.
Backend (live): table `sign_requests` (+ RLS, trigger `private.sign_rules`), RPC `public.send_sign_request`,
edge function `sign-doc` (GET info / GET pdf=1 / POST sign → stamps signature onto the PDF with pdf-lib, appends a
branded "Signing record" page with name, time, IP, device and the original's SHA-256, saves to documents bucket,
inserts an inbound `sign_signed` message for the job and queues a `sign_confirm` email to the customer).
Port into the source: add a real route/screen for both pages, and a "Send for signature" action on the job page.
