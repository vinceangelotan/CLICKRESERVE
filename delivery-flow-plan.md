# Delivery Flow Plan (Manual-Only, Single Transit State)

## Reality Constraints (Locked)
- No courier API and no automatic delivery webhooks.
- Delivery progress must be manually updated by admin/customer actions.
- Customer-facing delivery progression must only expose one transport state: In Transit.
- No customer-facing Arriving, Out for Delivery, or ETA states.

## Design Goal
Build delivery support for both directions while preserving current booking core states:
- Receive leg: shop to customer.
- Return leg: customer to shop.

Use delivery leg fields and manual events, without replacing current booking status flow.

## Core Principle
Main booking status remains unchanged and trusted:
- pending
- approved
- ongoing
- return
- completed

Delivery is an attached proof and handling layer, not a replacement lifecycle.

## Delivery Model (Two Legs)
Delivery is tracked separately per leg:
- receive_delivery_* for outbound shipment to customer.
- return_delivery_* for shipment back to shop.

## Required Order Fields
- receive_delivery_status
- receive_delivery_receipt_path
- receive_delivery_receipt_uploaded_at
- receive_delivery_receipt_uploaded_by
- receive_delivery_reference
- receive_delivery_notes
- receive_delivery_closed_at
- receive_delivery_closed_by

- return_delivery_status
- return_delivery_receipt_path
- return_delivery_receipt_uploaded_at
- return_delivery_receipt_uploaded_by
- return_delivery_reference
- return_delivery_notes
- return_delivery_closed_at
- return_delivery_closed_by

## Internal Tokens (Admin/Backend)
receive_delivery_status:
- not-required
- waiting-proof
- in-transit
- closed

return_delivery_status:
- not-required
- waiting-customer-proof
- in-transit
- closed

## Customer-Facing Rule (Non-Negotiable)
Customer sees delivery state label only when a leg is moving and has proof:
- Delivery In Transit

Customer never sees:
- waiting-proof
- waiting-customer-proof
- closed
- arriving-like labels

## Manual Event Rules
1. Admin uploads outbound delivery receipt (receive leg):
- Preconditions: receiving_method=delivery, booking status in approved or ongoing, order not terminal.
- Effects: save receipt, set receive_delivery_status=in-transit, set uploaded metadata.
- Customer result: sees Delivery In Transit plus View Delivery Receipt.

2. Customer uploads return delivery receipt (return leg):
- Preconditions: returning_method=delivery, booking status=return, order ownership valid.
- Effects: save receipt, set return_delivery_status=in-transit, set uploaded metadata.
- Admin result: sees return proof in booking details.

3. Admin closes receive leg:
- Preconditions: receive_delivery_status=in-transit.
- Effects: set receive_delivery_status=closed, set close metadata.
- Customer result: no extra delivery progression shown; core booking lifecycle continues.

4. Admin closes return leg:
- Preconditions: return_delivery_status=in-transit.
- Effects: set return_delivery_status=closed, set close metadata.
- Completion rule: Complete stays manual as-is.

## Endpoint Plan
1. Customer return upload endpoint:
- customer_order_upload_delivery_receipt.php
- direction fixed to return

2. Admin receive upload endpoint:
- admin/dashboard/upload_delivery_receipt.php
- direction fixed to receive

3. Admin close leg endpoint:
- admin/dashboard/close_delivery_leg.php
- leg values: receive or return

4. Repository helpers in config/customer_orders_repository.php:
- normalize_customer_order_delivery_status_token($value, $leg)
- customer_order_requires_receive_delivery($record)
- customer_order_requires_return_delivery($record)
- save_customer_order_delivery_receipt_from_data_url($imageDataUrl, $projectRoot, $orderId, $leg)
- upload_customer_order_delivery_receipt_for_customer(...)
- upload_customer_order_delivery_receipt_for_admin(...)
- close_customer_order_delivery_leg_by_admin($orderId, $leg)

## Guardrails (Foolproof Controls)
- Reject illegal transitions with 409 response.
- Never trust client-side status fields; always derive from repository record.
- Restrict direction by endpoint role:
- customer endpoint accepts return only.
- admin endpoint accepts receive only.
- Prevent terminal-order mutation.
- Keep uploads idempotent by overwriting same order-leg file in v1.
- Do not clear existing receipt if new upload save fails.
- Always normalize and sanitize file paths.
- Enforce image-only payload validation.

## Customer UI Plan
Order Status behavior:
- Receive leg:
- If receipt exists and receive leg is in-transit, show Delivery In Transit + View Delivery Receipt.
- Otherwise do not show speculative arrival state.

- Return leg:
- If status=return and returning_method=delivery and no return receipt, show Upload Delivery Receipt action.
- If return receipt exists and return leg is in-transit, show Delivery In Transit + View Uploaded Receipt.

Modal behavior:
- Reuse existing custom webpage modal style (same pattern as GCash modal).
- No browser-native modal APIs.

## Admin UI Plan
In booking detail image tab:
- Receive Delivery Receipt block.
- Return Delivery Receipt block.

Action visibility:
- Upload Receive Delivery Receipt: visible only when receive leg requires proof and not closed.
- Close Receive Delivery Leg: visible only when receive leg is in-transit.
- Close Return Delivery Leg: visible only when return leg is in-transit.

## Notification Plan
- Admin uploaded receive receipt: notify customer that delivery is in transit and receipt is available.
- Customer uploaded return receipt: notify admin review queue.
- Admin closed return leg: optional customer notice that return shipment was received.

## Storage Plan
Folders:
- assets/delivery_receipts/receive
- assets/delivery_receipts/return

Filename pattern:
- <order-id>-receive-delivery.<ext>
- <order-id>-return-delivery.<ext>

## Backward Compatibility
For existing records with no delivery fields:
- receiving_method=delivery => receive_delivery_status defaults to waiting-proof.
- returning_method=delivery => return_delivery_status defaults to waiting-customer-proof.
- non-delivery methods => not-required.

## Implementation Phases (Safe Order)
Phase 1:
- Add fields, normalizers, and repository helpers.
- Add asset save helper for delivery receipts.

Phase 2:
- Build customer return upload endpoint.
- Build admin receive upload endpoint.
- Build admin close leg endpoint.

Phase 3:
- Add customer upload/view UI for delivery receipts.
- Add admin image blocks and action buttons.

Phase 4:
- Add notifications and status-note copy.
- Final guardrail pass for illegal transitions and edge cases.

## No-API Verification Plan
Run manual scenario checks only, in this order:
1. Receive delivery booking approved, no receipt yet.
2. Admin uploads receive receipt, customer sees In Transit and image.
3. Admin closes receive leg, customer no longer sees speculative transit progression.
4. Return leg enters return, customer sees Upload Delivery Receipt action.
5. Customer uploads return receipt, admin sees proof.
6. Admin closes return leg.
7. Admin completes booking.
8. Reject customer upload when returning_method is not delivery.
9. Reject admin upload when receiving_method is not delivery.
10. Reject all delivery actions on terminal orders.

## Acceptance Criteria
- Customer-facing delivery progression exposes only Delivery In Transit.
- No ETA or arrival-like claims are shown to customer.
- Upload Delivery Receipt works for the correct actor and direction only.
- Illegal delivery actions are blocked with safe server responses.
- Existing core booking statuses remain stable.
- Complete remains manual finalization.
- UI remains readable and uses custom webpage modals only.
