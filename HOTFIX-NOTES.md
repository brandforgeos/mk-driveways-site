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

## 2026-10-31 — Quote builder (brief → Claude's multiple-choice questions → branded draft quote)
Standalone page + injected helper, nothing in the React bundle changed:
- `quote-builder/index.html` — directors only. List of briefs (Your turn / With Claude / Building / Quote ready / Closed),
  new brief (existing job or new customer, free-text brief, photos resized on the phone to ≤1400px JPEG and stored as
  base64 in `quote_brief_files`), and the brief thread: Claude's questions render as tappable single/multi-choice cards
  with an "own answer" box; when ready, links to the draft quote, its PDF and the job. Changes after a quote → new revision.
- `qb-share.js` (loaded from index.html) — "Quote builder" button on the Today, Jobs and job pages (job page: above
  "Send for signature"), with a badge for briefs waiting on you. sw.js: denylist gains /^\/quote-builder\//, qb-share.js precached.
Backend (live): tables `quote_briefs`, `quote_brief_messages`, `quote_brief_files`, `qb_uploads`, `qb_upload_chunks`;
trigger `private.qb_on_message` (status + phone notification); SQL API for the scheduled Claude run (service only):
`qb_take`, `qb_reply`, `qb_ensure_job`, `qb_upload_start`, `qb_chunk`, `qb_store`, `qb_finish`, `qb_fail`;
edge function `qb-store` (base64 chunks → documents bucket, SHA-256 checked). The quote itself is filed with
`import_quote`, acting as the director who wrote the brief.
The hourly "Hub quote builder" scheduled task on Lewis's Claude account does the thinking; its playbook and the PDF
render kit live in `quote-builder-kit/` on this branch (not deployed to the site).
Port into the source: a Quote builder route/screen and a job-page action.
- 2026-10-31 (later): photos — each photo gets a mark-up editor (draw, arrow, ↔ measure with size label, box, text label,
  5 colours, undo/clear) and a note. Stored in `quote_brief_files` as the marked copy (`data_b64`), the original
  (`original_b64`), `marked`, `note`. The hourly run fetches them through edge function `qb-photos` (returns the brief's
  photos AES-GCM encrypted with qb_courier_key) via the "Quote builder photos" GitHub Actions workflow on the qb-outbox branch.
- 2026-10-31 (night): SIGN-OFF LOCK. quotes.qb_hold / qb_signed_off_by / qb_signed_off_at + trigger private.qb_hold_guard:
  any quote NOT created by a signed-in person through the app (session_user 'authenticator' + authenticated JWT) is held;
  a held quote can't leave draft until a director signs it off (public.qb_sign_off, only from a real app session — Claude's
  runs connect as 'postgres' and can't); the hold can never be removed. Quote builder shows Held / Sign off / Signed off.
  Transfers are now keyless for the runs: PDFs sealed to the hub's public key (quote-builder-kit/hub_public_key.hex,
  private half in app_secrets.qb_hub_x25519_private), photos sealed by the hub to a one-off key the run makes.
- 2026-10-31 (night): PRICE BUILD-UP. Tables quote_costings (current), quote_costing_versions (every save kept),
  quote_costing_views (who opened it). public.qc_totals (line cost = (men×days or qty) × rate, +20% when vat), qb_costing_save
  (run; refuses if prices differ from the quote), qc_save (director edits, new version), qc_open; qb_sign_off now needs the
  build-up opened by the signer; trigger quotes_qc_budget fills job_financials budgets on acceptance. Page: ?costing=<quote id>
  — grouped lines, sources (Checked / EST / Your rate), live totals and margin, edit + comment, "Save and send changes to
  Claude" (posts the diff on the brief), print. Kit: example-costing.json, costing_check.py, playbook step 9.
- 2026-11-01: PRICE CHECK IS THE BUILD-UP. quote_brief_messages.costing; public.qb_price_check (run); trigger treats a Claude costing message as 'your turn'. Page: ?check=<msg id>&b=<brief> opens the same line-by-line editor with Approve these prices / Send changes back; the brief shows a Price check card with per-option price, cost and margin. Lewis's reply carries the (edited) build-up in its costing column.
