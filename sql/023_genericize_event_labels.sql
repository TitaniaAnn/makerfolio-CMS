-- 023_genericize_event_labels.sql
-- The default event-type labels were genericised as part of the "maker"
-- rebrand: "Pottery Show" -> "Show" and "Pottery Sale" -> "Sale"
-- (storefront_sale / class unchanged). EventTypes::DEFAULT_LABELS now carries
-- the new values, and init.sql seeds them for fresh installs.
--
-- Existing installs already have an `event_type_labels` settings row holding
-- the OLD seeded default, which overrides the code default — so without this
-- they'd keep showing "Pottery Show" / "Pottery Sale". Bump that row to the
-- new default, but ONLY when it still equals the exact old seeded JSON, so any
-- admin who customised their labels keeps their choices.
--
-- Safe to re-apply: once updated (or if already customised) the WHERE matches
-- no rows, so re-running is a harmless no-op.

UPDATE settings
   SET setting_value = '{"pottery_show":"Show","pottery_sale":"Sale","storefront_sale":"Storefront Sale","class":"Class"}'
 WHERE setting_key = 'event_type_labels'
   AND setting_value = '{"pottery_show":"Pottery Show","pottery_sale":"Pottery Sale","storefront_sale":"Storefront Sale","class":"Class"}';
