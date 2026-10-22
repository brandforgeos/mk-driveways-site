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
