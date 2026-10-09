# Yuzhou API

The independent backend uses the locked ThinkPHP 5.1.41 LTS framework and PHP 7.2 syntax. No existing customer database or payment configuration is included. Base URL is `/api/v1`. Send `Authorization: Bearer <token>` after signing in. JSON responses are `{ "code": 0, "message": "ok", "data": ... }`; failures also use an appropriate HTTP error status and a nonzero code. Lists return `{items,total,page,page_size}`. Fields use snake_case. Monetary values are integer cents (`price_cents`, `amount_cents`, `profit_cents`); ratios use basis points (10000 = 100%).

## Roles and isolation

`consumer` and `broker` use separate Apps. `merchant`, `agency` and `platform` use Web management. An agency is a broker company. Consumer accounts have a nullable organization. Only platform approval can grant institutional roles. Agency managers can suspend/reactivate and rename their own broker accounts, but cannot change roles, organization, identity or membership. Merchants are keyed by the 18-character `credit_code`; organization IDs only group account permissions. Broker orders remain invisible to the supplier merchant even when a supplier credit code is recorded.

## Authentication and public data

- `POST auth/register {username,password,nickname,invitation_code?}` and `POST auth/login {username,password}` return `{token,expires_at,user}`. Passwords contain at least 8 characters. Public registration only creates consumers. A filled invitation must be valid; only explicit invitation adds configured inviter growth, and creates no financial beneficiary relation.
- `GET auth/me`, `POST auth/logout`.
- `GET auth/invitation` returns the current user's stable `invitation_code`.
- `POST auth/sms {phone,purpose:login|register|bind}` accepts one mainland China mobile number (11 digits, optional `+86`) and returns `{challenge_id,purpose,expires_in,resend_after,delivery_status:"accepted"}`. Codes contain six digits, expire after 300 seconds, and may be resent after 60 seconds. An accepted request means the SMS provider accepted it, not that it was delivered. No phone, code, or provider credentials are returned.
- `POST auth/sms/verify {challenge_id,phone,purpose,code,nickname?,invitation_code?}` consumes a correct code once. `register` creates a new consumer with no organization only after verification; an optional valid invitation follows the existing growth rule. `login` returns `{token,expires_at,user}` for the verified phone identity only. It never matches or merges by an unverified username and never reactivates a suspended account or bypasses organization approval. SMS registration does not set a known password; the returned account ID remains usable for CRM association.
- `bind` requires a valid Bearer session on both requests, and the challenge belongs to that same user. Successful verification returns `{bound:true}` without issuing a new session or changing role/permissions. An existing phone cannot be transferred between users; a user with a different bound phone cannot automatically replace it. Existing password accounts can sign in and bind their own phone before using SMS login. `auth/me` and login responses expose `user.phone_bound:boolean`; the raw phone and a mask are not stored or exposed.
- Errors: `50311` SMS disabled/missing configuration, `50312` explicit provider rejection, `50313` unknown sending outcome (no automatic resend), `40103` incorrect/expired/used code, `40104` phone login unavailable/inactive account, `40303` another session's binding challenge, `40903` phone identity conflict, `42903` SMS rate limit. Five incorrect attempts exhaust a challenge; attempts commit even when the response is an error. Phone limits are 1 send/60 seconds, 5/hour, 10/day; IP limits are 20 sends/hour, 100/day, 30 verification attempts/15 minutes. `REMOTE_ADDR` is the source, not arbitrary forwarded headers.
- Verification serializes each phone with its stable bucket, and each binding account with its user row before the first consistent identity read. Only fully rolled-back MySQL 1205/1213 verification transactions retry, at most three attempts with expiry rechecked each time. External SMS sends, live nested transactions and other database errors never retry automatically.
- The first provider is Aliyun SMS, selected by development rather than specified by the contract. Separate `ALI_SMS_*` settings in `.env.example`, an approved sign/template, a dedicated RAM permission for `dysms:SendSms`, and explicit `ALI_SMS_ENABLED=1` are required; otherwise both SMS endpoints return 503. The stable independent `ALI_SMS_HMAC_KEY` must have at least 32 random characters: phone identities and code hashes depend on it, so rotation requires a controlled identity migration. The adapter uses PHP 7.2 cURL/Hash, TLS verification and [official ACS3 signing](https://help.aliyun.com/zh/sdk/product-overview/v3-request-structure-and-signature) for [SendSms 2017-05-25](https://help.aliyun.com/zh/sms/developer-reference/api-dysmsapi-2017-05-25-sendsms). Request URLs, headers, codes, phones and provider bodies must not be logged. Bootstrap reports `configured` only for complete enabled configuration; this is not a delivery/real-provider acceptance claim.
- Apply migration `007-sms-auth` before switching this code. `jobs.php` removes challenges expired more than one day and rate buckets untouched for seven days, at most 200 of each per run. Exact-key deletions commit independently and recheck current timestamps, avoiding holding several authentication locks during cleanup; verified phone identities are retained. `tests/backend/sms.php` uses offline HTTP mocks and isolated concurrent processes. Optional `--mysql` accepts only `yuzhou_acceptance_20261009` and never clears unrelated rows; real supplier delivery remains unverified.
- `GET bootstrap` exposes public branding, `vr_base_url`, environment/test flags and integration availability only; private configuration and financial rules are excluded. `VR_BASE_URL` is configured separately and individual `vr_url` requires a real model mapping.

## Catalog

`GET resources/:kind` supports `page`, `page_size`, `search`, and authenticated `mine=1`. `GET resources/:kind/:id` returns one record. `POST resources/:kind` creates and `PATCH resources/:kind/:id` edits with the current `version`. Shape:

```json
{"id":1,"kind":"boat_types","org_id":null,"merchant_credit_code":null,"title":"Boat type","status":"published","price_cents":300000,"payload":{"category":"yacht","length_m":"12","capacity":8,"daily_price_cents":300000,"deposit_cents":500000,"parameters":{"beam_m":3.5},"image_urls":[],"vr_url":"https://example.com/boat-3d"},"version":1}
```

Kinds and payloads:

- `boat_types`: `category,length_m,capacity,daily_price_cents,deposit_cents,parameters,image_urls,vr_url,description`. Structured parameters support comparison; `vr_url` links the user's existing 3D project.
- `boats`: `boat_type_id,serial_number,location,unavailable,image_urls`. These are actual boat instances. Buy an instance; rent a boat type and the server allocates instances.
- `upgrades`, `maintenance`: `boat_type_id` (optional), `description,image_urls,options,before_parameters,after_parameters`. An option is `{id,title,price_cents}` and is checked against server-side configuration.
- `products`: `stock,description,image_urls`; platform-only selling and fulfillment.
- `growth_products`: `points_price,stock,description,image_urls`; platform-managed points goods, without monetary checkout.
- `banners`: `placement:home|shop|learning|community,sort_order,target_url,image_urls`. Public list supports `placement`; targets must be HTTP or a local application path.
- `events`: `starts_at,capacity,location,description,image_urls,joint_merchant_codes`. Merchants create their own events for platform review. Platform joint events stay pending until an explicitly designated merchant approves. `POST events/:id/review {status:approved|rejected,notes}`.
- `courses`: free; `lessons:[{id,title,video_url,duration_seconds}],questions:[{question,options,answer}],pass_score,rewards`. Correct answers are never returned in public/student reads. Rewards use `{id,type,title,points?,coupon_id?,event_id?}`; types are `badge,growth,coupon,maintenance_coupon,physical_gift,event_invitation`. Issued reward meaning is immutable; use a new ID to change it.
- `coupons`: `discount_cents,min_amount_cents,expires_at,stock,order_types`.
- `posts`: `content,image_urls,video_url,post_type:forum|news,is_pinned,like_count,liked`. Official news and pinning require platform management; user submissions require manual moderation. AI integration is not represented as completed.
- Uploaded resource images and videos use the canonical `/api/v1/files/{id}` returned by upload. Local references must match the media type, be public uploads, and already be readable by the actor; publication cannot expose another user's unpublished upload. Pending references remain unavailable anonymously; published resource/lesson references permit anonymous media reads. External HTTP(S) media URLs remain supported.

The resource envelope aggregates small typed tables. Product stock, boat references, service pricing, events, course lessons/questions and coupon rules do not live solely in a JSON column.

## Orders, inventory and contracts

`GET inventory?boat_type_id=1&start_date=2026-11-01&end_date=2026-11-03` returns `available_count,boat_ids,days,daily_price_cents`. Rental dates include the start and exclude the end. Multiple physical instances are allocated without double booking.

`POST orders` accepts:

```json
{"type":"boat_rental","resource_id":1,"quantity":1,"start_date":"2026-11-01","end_date":"2026-11-03","coupon_id":null,"notes":""}
```

Types are `boat_purchase` (boats), `boat_rental` (boat_types), `boat_upgrade` (upgrades), `maintenance` (maintenance), `mall` (products), and `event` (events). Brokers/agency may add `customer_id` and agency additionally `broker_id`; the customer must be assigned to that broker. `option_ids` selects configured options, never client prices. Mall checkout may use `items:[{resource_id,quantity}]`. At most one coupon claim ID is accepted per order. Client `amount_cents` is not used to establish a checkout price.

`GET orders`, `GET orders/:id`; statuses are `awaiting_quote,quoted,awaiting_payment,paid,fulfilling,completed,cancelled,partially_refunded,refunded`.

Upgrade/maintenance creation can include `scheduled_at,service_location`. `POST orders/:id/schedule {scheduled_at,location}` updates their future service appointment. `POST orders/:id/progress {stage,percent,notes}` records authorized operator progress for paid orders; stages are `planned,preparing,installing,testing,delivery,completed`, percent is 0–100 and cannot decrease. It does not bypass normal customer completion. Order detail includes `schedule` and immutable `progress` entries.

- `POST orders/:id/quote {amount_cents,valid_until,notes,option_ids}`: authorized operator quotes; changing a quote invalidates the active contract link.
- `POST orders/:id/accept-quote {}`: only the customer accepts.
- `POST orders/:id/pay {channel,idempotency_key}`: only customer; `test` requires development/test plus the explicit environment switch. `free` is only for zero-value orders. Real unconfigured payment rejects with HTTP 503. Test responses are labelled test and do not represent real funds.
- `POST orders/:id/fulfill {status:fulfilling|completed,notes}`. Broker orders are fulfilled by that broker/agency; the customer can confirm completion after fulfillment begins.
- `POST orders/:id/cancel {reason}`: unpaid orders only.
- `POST uploads` multipart `file`, `private=1|0`; returns `{id,url,mime,size_bytes,sha256,private}`. PDFs are always private; all storage is outside the document root.
- `POST orders/:id/contract {file_id,title,notes}` creates a private, immutable file/version snapshot. `GET orders/:id/contracts`.
- `POST orders/:id/contract-review {version,status:approved|rejected,notes}`: platform review of the current version.
- `POST orders/:id/sign {version,accepted:true}`: customer accepts a reviewed version. It records acceptance evidence and a file hash; certified external e-signature remains a separate integration. Purchases and broker orders require an accepted current contract before payment.
- `GET files/:id`: contract/private files require their owner, the platform or an authorized order participant. Supplier merchants cannot retrieve broker-order contracts.

## Profit, dividends and refunds

`GET settings` / `PUT settings {distribution:{broker_bps,member_bps,settlement_days},growth:{invitation_points,rental_points_per_day,lesson_points,exam_points,post_points}}` are platform-only. No configured ratio means no automatic distribution. Growth defaults to zero until configured. Successful boat purchase grants six months membership once; rental test/actual successful payment grants configured daily growth once.

`POST orders/:id/profit-confirm {profit_cents,notes,beneficiaries:[{user_id,role:broker|member}]}` requires a per-order manual platform confirmation. At most one actual order broker and one valid member are selected explicitly; the total cannot exceed profit. The order freezes the rule version, percentage and cycle. Initial approval never infers profit from revenue or an old project formula.

`POST orders/:id/refund-request {amount_cents,reason}` is customer-only and records a pending request without altering money, profit or inventory. Platform reads `GET admin/refund-requests` and reviews `POST admin/refund-requests/:id/review {status:approved|rejected,notes}`. Approval is not a successful refund.

`POST orders/:id/refund {amount_cents,reason,channel,idempotency_key,request_id?}` is platform-only. Unconfigured real refunds reject and retain the request state; test refunds need the same environment guard as test payment. A successful partial refund freezes that order's ledger and marks profit `needs_reconfirmation`. Platform manually confirms the remaining profit; the original rule and beneficiaries are retained and only the target commission difference is appended. A full refund reverses the entire remaining commission. History is preserved; repeated idempotency keys do not repeat money changes. Already withdrawn funds create a balance debt and block additional withdrawals until covered.

`GET ledger`, `GET withdrawals`, `POST withdrawals {amount_cents,idempotency_key}`, `POST withdrawals/:id/review {status:approved|rejected,notes}`. Platform sees all; agency sees only its brokers; other roles see only themselves. The same caller/key/amount returns the original request; a changed amount returns 409. Requests without a valid key reject. Approval reserves a request; it does not pretend an external payout occurred. Marking paid without a configured payout integration rejects.

## Activity, learning, community and CRM

- `POST events/:id/enroll`; free events return enrollment, paid events return an order. Shared enrollment reservation and payment activation require an active consumer with platform-reviewed `identity_status:verified` and `member_until > now` (40341 for identity, 40342 for membership). Direct `POST orders {type:event}` and broker-created orders cannot bypass the customer gate. Platform material review is not an external identity-verification provider.
- `GET enrollments`; authorized organizer `POST enrollments/:id/check-in {ticket_code}`. Owner `POST enrollments/:id/cancel {ticket_code,reason?}` returns the enrollment row for a free pending/active ticket and releases capacity. A current ticket is required so stale retries cannot cancel a new enrollment; repeated cancellation of the same ticket is idempotent. Checked-in tickets reject. Expired membership does not prevent cancellation. Priced activity orders use ordinary unpaid order cancellation or paid `refund-request`; real refund rejection preserves the ticket until a confirmed refund succeeds.
- `GET learning/progress?page=&page_size=` returns only the caller's paginated progress, with `course_title` and `lesson_title` joined by course ID and that course's lesson key. Historical progress retains current titles when a course is archived. Missing titles are `null`; the interface should display “课程已不可用”/“课时已不可用” rather than an internal ID. Titles are read from their source records, not copied into progress. `POST learning/progress {course_id,lesson_id,position_seconds}`, `POST learning/exams {course_id,answers}`. Server scores exams; growth awards are idempotent.
- `GET learning/rewards` lists own reward claims. All lessons must be at least 90% complete and any configured exam passed before awards. Badges are `earned`, growth/coupons `issued`, gifts `pending_fulfillment`, event invitations `eligible` (no automatic enrollment). Exhausted/expired coupon rewards are `unavailable`; already claimed coupons do not repeat stock deductions. Platform `GET admin/course-rewards` and `POST admin/course-rewards/:id/fulfill {notes}` records physical fulfillment only. A claim preserves the original reward snapshot.
- `GET growth`, `GET coupons/my`, `POST coupons/:id/claim`.
- `GET cart`, `POST cart {product_id,quantity}`, `PATCH cart/:id {product_id,quantity}`, `DELETE cart/:id`.
- `GET posts/:id/comments`, `POST posts/:id/comments {content}`, `POST posts/:id/review {status:published|rejected,notes}`, `POST comments/:id/review {status:published|rejected}`.
- `GET admin/comments?status=pending|published|rejected&post_id=` provides the platform moderation queue. `POST/DELETE posts/:id/like` is caller-specific and idempotent; `POST posts/:id/pin {is_pinned:boolean}` is platform-only. `GET posts/:id/official-replies` is public; `POST posts/:id/official-replies {content}` requires platform authorization.
- `GET customers`, `POST customers {user_id|username,name,notes,broker_id?}`, `PATCH customers/:id {notes,stage}`. Customers and lead stages are broker/agency scoped.

## Applications and platform management

- `POST applications {kind:merchant|agency|broker|identity,org_id?,payload,file_ids:[...]}`; 1 to 10 owned private files. Merchant payload includes `organization_name,credit_code,legal_name`; broker application chooses an approved agency.
- `GET applications` returns own applications or all for platform. `POST applications/:id/review {status:approved|rejected,notes}` is platform-only.
- `GET admin/users`, `PATCH admin/users/:id {role,org_id,status,grant_membership:true}`. Membership grants a fixed six months. Agency update is restricted to its own brokers' nickname/status.
- `GET admin/organizations`, `POST admin/organizations`, `PATCH admin/organizations/:id`. Merchant approval requires `{credit_code,legal_name,license_file_id}`. The private license file must already be readable by the actor before association; a guessed file ID cannot grant access to unrelated identity/contract material. The same check applies when approving an applicant-owned linked license. The credit code is the merchant primary key and cannot be silently replaced.
- `GET admin/audit` records accountable transitions without passwords/tokens.

Communication, consultation, appointments, feedback, favorites, footprints and personal boat records are provided by the separate `Communication` module. See the root machine contract for these extension routes.

## Points shop and management sub-roles

`GET points-shop/products` is a public catalog list. `POST points-shop/redeem {product_id,quantity,idempotency_key}` atomically reserves stock and deducts integer growth points, returning a `pending_fulfillment` record. `GET points-shop/redemptions` lists own claims or all for platform. `POST points-shop/redemptions/:id/cancel {notes?}` permits the owner to cancel an unfulfilled claim. Platform `POST points-shop/redemptions/:id/review {status:fulfilled|rejected,notes}` requires delivery evidence for fulfilled; rejection/cancellation returns exact points and stock once. Physical goods are never marked shipped automatically.

`GET admin/permission-keys`, `GET/POST admin/roles`, `PATCH admin/roles/:id` and `GET/PUT admin/users/:id/roles {role_ids}` are platform management routes. A role is `{name,base_role:merchant|agency|platform,org_id,permissions,status}`; identity and organization are immutable. Permission keys are `catalog,orders,finance,settings,users,organizations,applications,customers,community,learning,operations,communication,reports,rbac`. Assigned roles union their permitted modules. Unassigned accounts retain baseline identity access. Inactive assigned roles do not restore unrestricted access.

Sub-roles only restrict the existing identity and organization checks. They cannot grant merchant access to broker orders or elevate agency accounts. A manager cannot delegate permissions above their own, replace higher access, change their own assigned access or use account identity/organization changes to gain unrestricted rights. Identity/organization changes require full platform access. Login and `auth/me` return effective `permissions`. Order profit/refund actions require `finance`; an orders-only account cannot execute them. User history requires `users` permission and platform identity.

`GET reports/summary` and `GET reports/dividends.xlsx` support `from_date,to_date` and existing order/ledger organization scopes. The latter returns a real Excel workbook. `GET admin/users/:id/history` is platform-only, returning public account fields, paginated customer orders and boat records.

## Structured CRM and boat locations

`GET customer-tags?org_id=&status=active|inactive` returns a paginated company tag list. Platform can filter a company or list all; brokers/agencies only see their own approved company. Agency/platform `POST customer-tags {org_id?,name,color,status?}` and `PATCH customer-tags/:id {name,color,status}` manage definitions; platform requires an agency `org_id` when creating. The organization is immutable, names unique per company, and color is a six-digit hex string.

`PUT customers/:id/tags {tag_ids:[...]}` replaces up to 20 distinct active tags from the same company; brokers can modify only their own customers. `GET customers?search=&tag_id=` and creation/update responses include `tags:[{id,name,color,status}]`. Inactivating a definition preserves its historical customer links and prevents new assignments.

`GET agency-targets?month=YYYY-MM&org_id=` and agency/platform `PUT agency-targets/:YYYY-MM {org_id?,target_amount_cents}` return `{org_id,month,configured,target_amount_cents,actual_paid_cents,actual_refund_cents,actual_net_paid_cents}`. Platform must select an approved agency; other allowed identities use their own company. Brokers can read but cannot edit the company target. Missing configuration is `configured:false,target_amount_cents:0`. Progress sums successful company broker-order payment and refund records within the calendar month in Asia/Shanghai, allowing refunds from earlier orders to reduce this month. It does not count quotation amounts or assume that an order's creation date is a receipt date. Both routes require the `customers` management module.

Boat `payload.latitude/longitude` are numbers in [-90,90]/[-180,180], or both null. They live in the independent `boat_locations` table. Published boat catalog responses expose supplied coordinates; neither demo seeding nor the server invents coordinates. Existing resource ownership, publication and optimistic-version checks still apply.

`GET recommendations?limit=6` is public with optional authentication, returning `{items,based_on,personalized}`. Only a consumer's own active favorites/views can rank published boat types; cold start returns recently published types. Limit is 1 to 24.
