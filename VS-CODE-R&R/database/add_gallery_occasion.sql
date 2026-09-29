USE rr_sweet_bites;

ALTER TABLE gallery_item
    ADD COLUMN occasion VARCHAR(100) NULL AFTER category;

CREATE INDEX idx_gallery_category_occasion
    ON gallery_item (category, occasion);
