-- Product gallery: up to 3 images + cover in `image`
USE zakapeiku;

ALTER TABLE products
    ADD COLUMN images TEXT DEFAULT NULL AFTER image;
