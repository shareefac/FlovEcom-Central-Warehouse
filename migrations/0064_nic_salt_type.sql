-- 0064_nic_salt_type.sql — "Nic salt" is its own product type on the item card (the owner, 9 Nov 2026: "Product type should be Nic Salt").
-- It keeps the rules of an e-liquid (CW\Catalogue\ItemRules: the 10 ml refill limit with nicotine, Vaping Products Duty). Nothing else
-- changes: no card row is touched (the cards are filled afterwards from the website's default category, a person's choice).
--
-- HOW IT IS APPLIED: one value added at the END of the column's list, so the stored values keep their numbers (an INSTANT change in
-- MySQL 8). Running it again changes nothing.

ALTER TABLE item_card MODIFY COLUMN product_type
  ENUM('e_liquid','shortfill','nic_shot','prefilled_pod','device_kit','single_use','coil','tank','accessory','nic_salt') NULL;
