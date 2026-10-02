-- R&R Sweet Bites - database (MySQL / MariaDB, works with XAMPP + phpMyAdmin)
-- Import this whole file in phpMyAdmin: Import tab > choose file > Go.
CREATE DATABASE IF NOT EXISTS rrcakes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rrcakes;

-- (No MySQL user is created here. config.php uses XAMPP's default 'root' login.)

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role          ENUM('customer','admin') NOT NULL DEFAULT 'customer',
  full_name     VARCHAR(60)  NOT NULL,
  email         VARCHAR(254) NOT NULL UNIQUE,
  contact       CHAR(11)     NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email        VARCHAR(254) NOT NULL,
  ip           VARCHAR(45)  NOT NULL,
  attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attempts (email, ip, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no       VARCHAR(20)  NOT NULL UNIQUE,          -- e.g. RR-2041
  request_no     VARCHAR(20)  NOT NULL,                 -- e.g. RQ-2039
  customer_id    INT UNSIGNED NOT NULL,
  requested_date DATE         NOT NULL,
  cake_size      VARCHAR(100) NOT NULL,
  flavor         VARCHAR(150) NOT NULL,
  design_desc    TEXT         NOT NULL,
  ref_path       VARCHAR(80)  NULL,                     -- saved file name inside /uploads
  ref_original   VARCHAR(150) NULL,
  base_price     DECIMAL(10,2) NOT NULL DEFAULT 0,
  rush_fee       DECIMAL(10,2) NOT NULL DEFAULT 0,
  status         ENUM('pending_payment','payment_review','confirmed','payment_rejected','cancelled','completed')
                 NOT NULL DEFAULT 'pending_payment',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pay_no         VARCHAR(20)  NOT NULL UNIQUE,          -- e.g. PAY-002
  order_id       INT UNSIGNED NOT NULL,
  method         ENUM('GCash','Bank Transfer','Cash') NOT NULL,
  ptype          ENUM('Downpayment','Balance') NOT NULL DEFAULT 'Downpayment',
  amount         DECIMAL(10,2) NOT NULL,
  reference_no   VARCHAR(60)  NOT NULL,
  proof_path     VARCHAR(80)  NULL,
  proof_original VARCHAR(150) NULL,
  status         ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  remarks        VARCHAR(500) NULL,
  submitted_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_by    INT UNSIGNED NULL,
  verified_at    DATETIME NULL,
  FOREIGN KEY (order_id)    REFERENCES orders(id),
  FOREIGN KEY (verified_by) REFERENCES users(id),
  INDEX idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  message    VARCHAR(600) NOT NULL,
  is_read    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- DEMO DATA (optional): the two pending payments from the admin mock-up.
-- Delete everything below this line if you don't want it.
-- The sample customers cannot log in ('!' is not a valid password hash).
-- ---------------------------------------------------------------------------
INSERT INTO users (role, full_name, email, contact, password_hash) VALUES
 ('customer','Rico Bautista',    'ricoyarnbautista22@gmail.com','09171234567','!'),
 ('customer','Atasha Barrameda', 'bleumiareen11@gmail.com',     '09179876543','!');

INSERT INTO orders (order_no, request_no, customer_id, requested_date, cake_size, flavor, design_desc, base_price, rush_fee, status) VALUES
 ('RR-2039','RQ-2039',(SELECT id FROM users WHERE email='ricoyarnbautista22@gmail.com'),'2026-11-02','Custom Sheet Cake','Chocolate Fudge Caramel','Custom design with celebration theme',6000.00,3000.00,'payment_review'),
 ('RR-2041','RQ-2041',(SELECT id FROM users WHERE email='bleumiareen11@gmail.com'),'2026-10-15','2-Tier Round Cake (8" + 6")','Vanilla Buttercream & Strawberry','Pastel floral theme with gold accents',9000.00,0.00,'payment_review');

INSERT INTO payments (pay_no, order_id, method, ptype, amount, reference_no, status, submitted_at) VALUES
 ('PAY-002',(SELECT id FROM orders WHERE order_no='RR-2039'),'Bank Transfer','Downpayment',1800.00,'BDO-TR991234','pending','2026-09-22 10:15:00'),
 ('PAY-003',(SELECT id FROM orders WHERE order_no='RR-2041'),'GCash','Downpayment',1800.00,'GCASH-1122334455','pending','2026-09-18 14:40:00');
