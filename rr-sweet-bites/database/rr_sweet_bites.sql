-- =========================================================
-- R&R Sweet Bites — Custom Cake Ordering System
-- Database Schema (MySQL 8.0.16+ / MariaDB 10.2+)
-- Converted from the PostgreSQL version.
-- Import via phpMyAdmin (Import tab) or:  mysql -u root -p < R_Rschema_mysql.sql
-- =========================================================

CREATE DATABASE IF NOT EXISTS rr_sweet_bites
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;

USE rr_sweet_bites;

-- ---------------------------------------------------------
-- CUSTOMER (FR-1, FR-2, FR-4)
-- ---------------------------------------------------------
CREATE TABLE customer (
    customer_id     INT AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(150) NOT NULL,
    email           VARCHAR(255) NOT NULL UNIQUE,
    contact_number  VARCHAR(30)  NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- ADMIN (FR-3, FR-4)
-- ---------------------------------------------------------
CREATE TABLE admin (
    admin_id        INT AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(150) NOT NULL,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- INITIAL ADMIN ACCOUNT FOR R&R ADMIN PORTAL
-- Password: Admin@12345
-- The password is stored as a PHP password_hash() value.
-- ---------------------------------------------------------
INSERT INTO admin (full_name, email, password_hash)
VALUES (
    'R&R Admin',
    'admin@email.com',
    '$2y$10$4KxMFJZi.jUXGySSjl9PM.bWpFW1B/G8jP9v5oBZawxKxNI7jQhYu'
);

-- ---------------------------------------------------------
-- WEEKLY_AVAILABILITY (FR-13, FR-14)
-- ---------------------------------------------------------
CREATE TABLE weekly_availability (
    week_id         INT AUTO_INCREMENT PRIMARY KEY,
    week_start_date DATE NOT NULL,
    week_end_date   DATE NOT NULL,
    status          ENUM('open','closed','fully_booked') NOT NULL DEFAULT 'open',
    set_by_admin_id INT  NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_week_range CHECK (week_end_date >= week_start_date),
    CONSTRAINT uq_week_range UNIQUE (week_start_date, week_end_date),
    CONSTRAINT fk_week_admin FOREIGN KEY (set_by_admin_id) REFERENCES admin(admin_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- GALLERY_ITEM (FR-5, FR-6)
-- ---------------------------------------------------------
CREATE TABLE gallery_item (
    gallery_id      INT AUTO_INCREMENT PRIMARY KEY,
    admin_id        INT          NOT NULL,
    image_url       VARCHAR(500) NOT NULL,
    title           VARCHAR(150) NOT NULL,
    description     TEXT,
    category        VARCHAR(100),
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_gallery_admin FOREIGN KEY (admin_id) REFERENCES admin(admin_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- ORDER (FR-7, FR-8, FR-11, FR-12, FR-15)
-- `order` is a reserved word in MySQL, so it must be backticked.
-- ---------------------------------------------------------
CREATE TABLE `order` (
    order_id            INT AUTO_INCREMENT PRIMARY KEY,
    customer_id         INT NOT NULL,
    week_id             INT NULL,
    cake_type           ENUM('cake','cupcake','number_shaped_cake') NOT NULL,
    design_description  TEXT         NOT NULL,
    flavor              VARCHAR(150) NOT NULL,
    num_layers          INT          NOT NULL,
    num_tiers           INT          NOT NULL,
    preferred_date      DATE         NOT NULL,
    is_rush             BOOLEAN      NOT NULL DEFAULT FALSE,

    -- FR-11: weighted complexity score inputs and result
    design_intricacy_rating INT      NOT NULL,
    decoration_requirements TEXT,
    structural_requirements TEXT,
    complexity_score        DECIMAL(6,2) NOT NULL,
    difficulty_level        ENUM('low','medium','high') NOT NULL,

    status              ENUM(
                            'pending_review',
                            'quoted',
                            'confirmed',
                            'downpayment_pending_verification',
                            'in_production',
                            'out_for_delivery',
                            'ready_for_pickup',
                            'completed',
                            'cancellation_requested',
                            'cancelled'
                        ) NOT NULL DEFAULT 'pending_review',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_num_layers CHECK (num_layers > 0),
    CONSTRAINT chk_num_tiers  CHECK (num_tiers > 0),
    CONSTRAINT fk_order_customer FOREIGN KEY (customer_id) REFERENCES customer(customer_id),
    CONSTRAINT fk_order_week     FOREIGN KEY (week_id)     REFERENCES weekly_availability(week_id),
    INDEX idx_order_customer (customer_id),
    INDEX idx_order_status   (status),
    INDEX idx_order_week     (week_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- ORDER_REFERENCE_IMAGE (FR-7)
-- ---------------------------------------------------------
CREATE TABLE order_reference_image (
    image_id        INT AUTO_INCREMENT PRIMARY KEY,
    order_id        INT          NOT NULL,
    image_url       VARCHAR(500) NOT NULL,
    uploaded_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_refimg_order FOREIGN KEY (order_id) REFERENCES `order`(order_id) ON DELETE CASCADE,
    INDEX idx_ref_image_order (order_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- QUOTATION (FR-9, FR-10)
-- ---------------------------------------------------------
CREATE TABLE quotation (
    quotation_id    INT AUTO_INCREMENT PRIMARY KEY,
    order_id        INT NOT NULL,
    admin_id        INT NOT NULL,
    quoted_price    DECIMAL(10,2) NOT NULL,
    remarks         TEXT,
    version_number  INT NOT NULL DEFAULT 1,
    status          ENUM('pending','accepted','revision_requested','revised','rejected')
                    NOT NULL DEFAULT 'pending',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_quoted_price CHECK (quoted_price >= 0),
    CONSTRAINT uq_quotation_version UNIQUE (order_id, version_number),
    CONSTRAINT fk_quotation_order FOREIGN KEY (order_id) REFERENCES `order`(order_id),
    CONSTRAINT fk_quotation_admin FOREIGN KEY (admin_id) REFERENCES admin(admin_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- PAYMENT (FR-16, FR-17, FR-18)
-- ---------------------------------------------------------
CREATE TABLE payment (
    payment_id           INT AUTO_INCREMENT PRIMARY KEY,
    order_id             INT NOT NULL,
    payment_type         ENUM('downpayment','balance') NOT NULL,
    payment_method       ENUM('gcash','bank_transfer','cash') NOT NULL,
    amount               DECIMAL(10,2) NOT NULL,
    proof_image_url      VARCHAR(500),
    status               ENUM('pending_verification','verified','rejected')
                         NOT NULL DEFAULT 'pending_verification',
    verified_by_admin_id INT NULL,
    verified_at          DATETIME NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_amount CHECK (amount >= 0),
    CONSTRAINT chk_proof_required CHECK (
        payment_method = 'cash' OR proof_image_url IS NOT NULL
    ),
    CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES `order`(order_id),
    CONSTRAINT fk_payment_admin FOREIGN KEY (verified_by_admin_id) REFERENCES admin(admin_id),
    INDEX idx_payment_order  (order_id),
    INDEX idx_payment_status (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- FULFILLMENT (one-to-one with order) (FR-19, FR-20)
-- ---------------------------------------------------------
CREATE TABLE fulfillment (
    fulfillment_id            INT AUTO_INCREMENT PRIMARY KEY,
    order_id                  INT NOT NULL UNIQUE,
    method                    ENUM('shop_delivery','third_party_courier','store_pickup') NOT NULL,
    delivery_address          VARCHAR(500),
    pickup_preferred_time     TIME,
    delivery_fee              DECIMAL(8,2) NOT NULL DEFAULT 0,
    estimated_distance_km     DECIMAL(6,2),
    estimated_travel_time_min INT,
    created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_delivery_address CHECK (
        method = 'store_pickup' OR delivery_address IS NOT NULL
    ),
    CONSTRAINT fk_fulfillment_order FOREIGN KEY (order_id) REFERENCES `order`(order_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- DELIVERY_TRACKING (FR-21, FR-22)
-- ---------------------------------------------------------
CREATE TABLE delivery_tracking (
    tracking_id       INT AUTO_INCREMENT PRIMARY KEY,
    fulfillment_id    INT NOT NULL,
    status            ENUM('not_started','preparing','out_for_delivery',
                           'delivered','delayed','failed') NOT NULL,
    location_lat      DECIMAL(9,6),
    location_lng      DECIMAL(9,6),
    courier_reference VARCHAR(150),
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tracking_fulfillment FOREIGN KEY (fulfillment_id) REFERENCES fulfillment(fulfillment_id),
    INDEX idx_tracking_fulfillment (fulfillment_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- MESSAGE (FR-23)
-- sender_id / recipient_id are polymorphic (customer_id or admin_id),
-- so they are intentionally not foreign keys.
-- ---------------------------------------------------------
CREATE TABLE message (
    message_id      INT AUTO_INCREMENT PRIMARY KEY,
    sender_type     ENUM('customer','admin') NOT NULL,
    sender_id       INT NOT NULL,
    recipient_type  ENUM('customer','admin') NOT NULL,
    recipient_id    INT NOT NULL,
    order_id        INT NULL,
    message_text    TEXT NOT NULL,
    sent_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_message_order FOREIGN KEY (order_id) REFERENCES `order`(order_id),
    INDEX idx_message_order     (order_id),
    INDEX idx_message_recipient (recipient_type, recipient_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- NOTIFICATION (FR-24)
-- ---------------------------------------------------------
CREATE TABLE notification (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id        INT NOT NULL,
    recipient_type  ENUM('customer','admin') NOT NULL,
    recipient_id    INT NOT NULL,
    message         VARCHAR(500) NOT NULL,
    is_read         BOOLEAN NOT NULL DEFAULT FALSE,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_order FOREIGN KEY (order_id) REFERENCES `order`(order_id),
    INDEX idx_notification_recipient (recipient_type, recipient_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- CANCELLATION (FR-25)
-- ---------------------------------------------------------
CREATE TABLE cancellation (
    cancellation_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id        INT NOT NULL UNIQUE,
    reason          TEXT NOT NULL,
    status          ENUM('requested','approved','rejected') NOT NULL DEFAULT 'requested',
    requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at     DATETIME NULL,
    CONSTRAINT fk_cancellation_order FOREIGN KEY (order_id) REFERENCES `order`(order_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- REFUND (FR-26)
-- ---------------------------------------------------------
CREATE TABLE refund (
    refund_id              INT AUTO_INCREMENT PRIMARY KEY,
    cancellation_id        INT NOT NULL UNIQUE,
    decision               ENUM('approved','rejected') NOT NULL,
    refund_amount          DECIMAL(10,2) NOT NULL,
    processed_by_admin_id  INT NOT NULL,
    processed_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_refund_amount CHECK (refund_amount >= 0),
    CONSTRAINT fk_refund_cancellation FOREIGN KEY (cancellation_id) REFERENCES cancellation(cancellation_id),
    CONSTRAINT fk_refund_admin FOREIGN KEY (processed_by_admin_id) REFERENCES admin(admin_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- FEEDBACK (FR-29)
-- ---------------------------------------------------------
CREATE TABLE feedback (
    feedback_id     INT AUTO_INCREMENT PRIMARY KEY,
    order_id        INT NOT NULL UNIQUE,
    customer_id     INT NOT NULL,
    rating          SMALLINT NOT NULL,
    feedback_text   TEXT,
    submitted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT fk_feedback_order    FOREIGN KEY (order_id)    REFERENCES `order`(order_id),
    CONSTRAINT fk_feedback_customer FOREIGN KEY (customer_id) REFERENCES customer(customer_id)
) ENGINE=InnoDB;

-- =========================================================
-- End of schema
-- =========================================================
