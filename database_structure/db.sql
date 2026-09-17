
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE purchase_order_items TO runai_app;
GRANT ALL PRIVILEGES ON TABLE debit_notes TO runai_app;
GRANT ALL PRIVILEGES ON TABLE  debit_note_items TO runai_app;
GRANT ALL PRIVILEGES ON TABLE sales_orders TO runai_app;
GRANT ALL PRIVILEGES ON TABLE sales_order_items TO runai_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO runai_app;
GRANT ALL PRIVILEGES ON TABLE product_batches TO runai_app;
SELECT * FROM  purchase_order_items;
SELECT * FROM purchase_orders;

CREATE TABLE company_settings (
    id INTEGER DEFAULT 1 PRIMARY KEY CHECK (id = 1), -- Ensures only ONE row exists
    company_name VARCHAR(100) NOT NULL,
    location TEXT,
    email VARCHAR(100),
    mobile VARCHAR(50),
    website VARCHAR(100),
    logo_path VARCHAR(255),
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
SELECT * FROM company_settings;
--NO DEPENCY ON ANYTHIN
CREATE TABLE currencies(
currency_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
name VARCHAR(50) NOT NULL,
code VARCHAR(3) UNIQUE NOT NULL,
symbol VARCHAR(5) UNIQUE NOT NULL,
exchange_rate NUMERIC(10,4) DEFAULT 1.00,
is_active BOOLEAN DEFAULT TRUE,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
SELECT * FROM currencies;

--CREATE A TABLE TO STORE ROLES
CREATE TABLE roles (
    role_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,     
    description TEXT,
    is_system_role BOOLEAN DEFAULT FALSE,   
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP, 
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
SELECT * FROM roles;
INSERT INTO roles (name, description, is_system_role)
VALUES 
('Administrator', 'Full access to all system features and settings', TRUE),
('Super User', 'Elevated privileges for troubleshooting and advanced configurations', TRUE),
('Moderator', 'Can manage user content, enforce rules, and handle reports', FALSE);

CREATE TABLE permissions (
    permission_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,      
    module VARCHAR(50) NOT NULL,           
    description TEXT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
SELECT * FROM permissions;

INSERT INTO permissions(name, module, description)
VALUES
('export_report', 'Reports', 'Allows exporting reports to external formats (CSV, PDF, etc.)'),
('view_reports', 'Reports', 'Grants access to view system-generated reports'),
('assign_roles', 'Users', 'Ability to assign or change roles for users');

CREATE TABLE units_of_measurement (
    uom_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,             
    abbreviation VARCHAR(15) UNIQUE NOT NULL,      
    allow_decimals BOOLEAN DEFAULT FALSE,          
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
SELECT * FROM units_of_measurement;
INSERT INTO units_of_measurement(uom_name, uom_short_nam, allow_decimals)
VALUES
('kilogram', 'kg', TRUE, 'Standard weight unit for bulk goods'),
('gram', 'g', TRUE, 'Smaller weight unit for precision'),
('liter', 'L', TRUE, 'Standard volume unit for liquids'),
('milliliter', 'mL', TRUE, 'Small volume unit for liquids');

CREATE TABLE categories (
    category_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    parent_id INTEGER REFERENCES categories(category_id) ON DELETE SET NULL, 
    name VARCHAR(100) UNIQUE NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,       
    description TEXT,                        
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
SELECT * FROM categories;

CREATE TABLE user_roles ( 
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE, 
    role_id INTEGER NOT NULL REFERENCES roles(role_id) ON DELETE CASCADE, 
    assigned_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL, 
    assigned_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP, 
    PRIMARY KEY (user_id, role_id) 
);
SELECT * FROM user_roles;
INSERT INTO user_roles(user_id, role_id, assigned)
VALUES
(1, 1, 1);

SELECT * FROM users;
CREATE TABLE suppliers (
    supplier_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name VARCHAR(255) UNIQUE NOT NULL,
    contact_name VARCHAR(255),                 
    phone VARCHAR(50) UNIQUE NOT NULL,          
    email VARCHAR(255) UNIQUE,                  
    tax_pin VARCHAR(50) UNIQUE,                 
    address TEXT,
    is_active BOOLEAN DEFAULT TRUE,             
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

SELECT * FROM suppliers;
CREATE TABLE lpo_items (
    lpo_item_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    lpo_id INTEGER NOT NULL REFERENCES lpos(lpo_id) ON DELETE CASCADE, 
    product_id INTEGER NOT NULL REFERENCES products(product_id),
    quantity_ordered NUMERIC(12, 3) NOT NULL CHECK (quantity_ordered > 0), 
    quantity_fulfilled NUMERIC(12, 3) NOT NULL DEFAULT 0,                  
    unit_price NUMERIC(12, 2) NOT NULL,
    subtotal NUMERIC(12, 2) GENERATED ALWAYS AS (quantity_ordered * unit_price) STORED,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
SELECT * FROM lpo_items;

CREATE TABLE grns (
    grn_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    grn_number VARCHAR(100) UNIQUE NOT NULL,  
    supplier_id INTEGER NOT NULL REFERENCES suppliers(supplier_id), 
    lpo_id INTEGER REFERENCES lpos(lpo_id),                         
    received_by INTEGER NOT NULL REFERENCES users(user_id),
    receipt_date DATE DEFAULT CURRENT_DATE,
    supplier_invoice_no VARCHAR(100),                
    currency_id INTEGER NOT NULL REFERENCES currencies(currency_id),
    total_amount NUMERIC(12, 2) DEFAULT 0,
    notes TEXT,
    status VARCHAR(20) DEFAULT 'draft' CHECK (
        status IN ('draft', 'confirmed', 'partially_received', 'cancelled')
    ),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
SELECT * FROM grns;

CREATE TABLE grn_items (
    grn_item_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    grn_id INTEGER NOT NULL REFERENCES grns(grn_id) ON DELETE CASCADE,    
    product_id INTEGER NOT NULL REFERENCES products(product_id),
    lpo_item_id INTEGER REFERENCES lpo_items(lpo_item_id),                
    batch_number VARCHAR(100),                                            
    expiry_date DATE,                                                      
    quantity_ordered NUMERIC(12, 3),                                      
    quantity_received NUMERIC(12, 3) NOT NULL CHECK (quantity_received >= 0), 
    quantity_rejected NUMERIC(12, 3) NOT NULL DEFAULT 0,                  
    unit_cost NUMERIC(12, 2) NOT NULL,
    subtotal NUMERIC(12, 2) GENERATED ALWAYS AS (quantity_received * unit_cost) STORED,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE product_batches (
    batch_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id INTEGER NOT NULL REFERENCES products(product_id),
    supplier_id INTEGER REFERENCES suppliers(supplier_id),         
    grn_item_id INTEGER REFERENCES grn_items(grn_item_id),          
    batch_number VARCHAR(100) NOT NULL,
    expiry_date DATE NOT NULL,
    manufacture_date DATE,
    quantity_received NUMERIC(12, 3) NOT NULL CHECK (quantity_received > 0),  
    quantity_remaining NUMERIC(12, 3) NOT NULL CHECK (quantity_remaining >= 0),
    unit_cost NUMERIC(12, 2) NOT NULL,
    received_by INTEGER REFERENCES users(user_id),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,             
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,               
    
    UNIQUE (product_id, batch_number)
);
CREATE TABLE proforma_invoices (
    pi_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    pi_number VARCHAR(100) UNIQUE NOT NULL, 
    quote_id INTEGER,                                 
    customer_id INTEGER NOT NULL,                    
    prepared_by INTEGER NOT NULL REFERENCES users(user_id),
    issue_date DATE DEFAULT CURRENT_DATE,
    expiry_date DATE,
    currency_id INTEGER NOT NULL REFERENCES currencies(currency_id),
    subtotal NUMERIC(12, 2) DEFAULT 0,
    discount_amount NUMERIC(12, 2) DEFAULT 0,
    tax_amount NUMERIC(12, 2) DEFAULT 0,
    total_amount NUMERIC(12, 2) DEFAULT 0,
    notes TEXT,
    terms TEXT,
    status VARCHAR(20) DEFAULT 'draft' CHECK (
        status IN ('draft', 'sent', 'confirmed', 'converted', 'cancelled')
    ),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE stock_holds (
    hold_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id INTEGER NOT NULL REFERENCES products(product_id),
    pi_id INTEGER NOT NULL REFERENCES proforma_invoices(pi_id) ON DELETE CASCADE, 
    quantity NUMERIC(12, 3) NOT NULL CHECK (quantity > 0),                       
    held_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    released_at TIMESTAMPTZ,                                                     
    is_active BOOLEAN DEFAULT TRUE,                                              
    UNIQUE (product_id, pi_id)
);

CREATE TABLE customers (
    customer_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_name VARCHAR(255),                 
    first_name VARCHAR(100),                    
    last_name VARCHAR(100),
    email VARCHAR(255) UNIQUE,
    phone VARCHAR(20) UNIQUE,
    tax_pin VARCHAR(50) UNIQUE,                
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
  
    CONSTRAINT chk_customer_name CHECK (
        (first_name IS NOT NULL) OR (company_name IS NOT NULL)
    )
);
SELECT * FROM customers;

CREATE TABLE customer_addresses (
    address_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id INTEGER NOT NULL REFERENCES customers(customer_id) ON DELETE CASCADE,
    address_type VARCHAR(20) NOT NULL CHECK (address_type IN ('billing', 'shipping')),
    recipient_name VARCHAR(255),                
    recipient_phone VARCHAR(20),               
    address_line1 VARCHAR(255) NOT NULL,
    address_line2 VARCHAR(255),
    city VARCHAR(100) NOT NULL,
    county VARCHAR(100),
    postal_code VARCHAR(50),                   
    country VARCHAR(100) DEFAULT 'Kenya',
    delivery_instructions TEXT,                
    is_default BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
SELECT * FROM suppliers;
CREATE TABLE quotes (
    quote_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    quote_number VARCHAR(100) UNIQUE NOT NULL, 
    customer_id INTEGER NOT NULL REFERENCES customers(customer_id), 
    prepared_by INTEGER NOT NULL REFERENCES users(user_id),
    issue_date DATE DEFAULT CURRENT_DATE,
    expiry_date DATE NOT NULL,                                     
    currency_id INTEGER NOT NULL REFERENCES currencies(currency_id),
    subtotal NUMERIC(12, 2) DEFAULT 0,
    discount_amount NUMERIC(12, 2) DEFAULT 0,
    tax_amount NUMERIC(12, 2) DEFAULT 0,
    total_amount NUMERIC(12, 2) DEFAULT 0,
    notes TEXT,
    terms TEXT,                                                     
    status VARCHAR(20) DEFAULT 'draft' CHECK (
        status IN ('draft', 'sent', 'accepted', 'rejected', 'expired', 'converted')
    ),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE quote_items (
    quote_item_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    quote_id INTEGER NOT NULL REFERENCES quotes(quote_id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(product_id),
    quantity NUMERIC(12, 3) NOT NULL CHECK (quantity > 0),                  
    unit_price NUMERIC(12, 2) NOT NULL,
    discount NUMERIC(12, 2) DEFAULT 0,
    subtotal NUMERIC(12, 2) GENERATED ALWAYS AS (quantity * unit_price - discount) STORED,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

SELECT * FROM products;

DROP TABLE IF EXISTS lpos CASCADE;
DROP TABLE IF EXISTS lpo_items CASCADE;
DROP TABLE IF EXISTS grns CASCADE;
DROP TABLE IF EXISTS grn_items CASCADE;
DROP TABLE IF EXISTS product_batches CASCADE;

CREATE TABLE purchase_orders (
    po_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    po_number VARCHAR(100) UNIQUE NOT NULL,       
    supplier_id INTEGER NOT NULL REFERENCES suppliers(supplier_id), 
    issued_by INTEGER NOT NULL REFERENCES users(user_id),
    issue_date DATE DEFAULT CURRENT_DATE,
    expected_delivery_date DATE,
    currency_id INTEGER NOT NULL REFERENCES currencies(currency_id),
    subtotal NUMERIC(12, 2) DEFAULT 0,
    tax_amount NUMERIC(12, 2) DEFAULT 0,
    total_amount NUMERIC(12, 2) DEFAULT 0,
    delivery_address TEXT,
    notes TEXT,
    terms TEXT,
    status VARCHAR(20) DEFAULT 'draft' CHECK (
        status IN ('draft', 'sent', 'acknowledged', 'partially_received', 'received', 'cancelled')
    ),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE purchase_order_items (
    po_item_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    po_id INTEGER NOT NULL REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(product_id),
    quantity_ordered NUMERIC(12, 3) NOT NULL CHECK (quantity_ordered > 0),
    quantity_fulfilled NUMERIC(12, 3) NOT NULL DEFAULT 0,
    unit_price NUMERIC(12, 2) NOT NULL,
    subtotal NUMERIC(12, 2) GENERATED ALWAYS AS (quantity_ordered * unit_price) STORED,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE grns (
    grn_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    grn_number VARCHAR(100) UNIQUE NOT NULL,  
    supplier_id INTEGER NOT NULL REFERENCES suppliers(supplier_id), 
    po_id INTEGER REFERENCES purchase_orders(po_id),                
    received_by INTEGER NOT NULL REFERENCES users(user_id),
    receipt_date DATE DEFAULT CURRENT_DATE,
    supplier_invoice_no VARCHAR(100),                
    currency_id INTEGER NOT NULL REFERENCES currencies(currency_id),
    total_amount NUMERIC(12, 2) DEFAULT 0,
    notes TEXT,
    status VARCHAR(20) DEFAULT 'draft' CHECK (
        status IN ('draft', 'confirmed', 'partially_received', 'cancelled')
    ),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE grn_items (
    grn_item_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    grn_id INTEGER NOT NULL REFERENCES grns(grn_id) ON DELETE CASCADE,     
    product_id INTEGER NOT NULL REFERENCES products(product_id),
    po_item_id INTEGER REFERENCES purchase_order_items(po_item_id),        
    batch_number VARCHAR(100),                                             
    expiry_date DATE,                                                      
    quantity_ordered NUMERIC(12, 3),                                       
    quantity_received NUMERIC(12, 3) NOT NULL CHECK (quantity_received >= 0), 
    quantity_rejected NUMERIC(12, 3) NOT NULL DEFAULT 0,                   
    unit_cost NUMERIC(12, 2) NOT NULL,
    subtotal NUMERIC(12, 2) GENERATED ALWAYS AS (quantity_received * unit_cost) STORED,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 5. Create Product Batches
CREATE TABLE product_batches (
    batch_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id INTEGER NOT NULL REFERENCES products(product_id),
    supplier_id INTEGER REFERENCES suppliers(supplier_id),          
    grn_item_id INTEGER REFERENCES grn_items(grn_item_id),          
    batch_number VARCHAR(100) NOT NULL,
    expiry_date DATE,
    manufacture_date DATE,
    quantity_received NUMERIC(12, 3) NOT NULL CHECK (quantity_received > 0),   
    quantity_remaining NUMERIC(12, 3) NOT NULL CHECK (quantity_remaining >= 0),
    unit_cost NUMERIC(12, 2) NOT NULL,
    received_by INTEGER REFERENCES users(user_id),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,               
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,               
    
    UNIQUE (product_id, batch_number)
);

CREATE TABLE debit_notes (
    debit_note_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    debit_note_number VARCHAR(100) UNIQUE NOT NULL, 
    grn_id INTEGER NOT NULL REFERENCES grns(grn_id), 
    supplier_id INTEGER NOT NULL REFERENCES suppliers(supplier_id),
    issued_by INTEGER NOT NULL REFERENCES users(user_id),
    issue_date DATE DEFAULT CURRENT_DATE,
    total_refund_amount NUMERIC(12, 2) DEFAULT 0,
    status VARCHAR(20) DEFAULT 'draft' CHECK (
        status IN ('draft', 'sent', 'processed', 'cancelled')
    ),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE debit_note_items (
    debit_item_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    debit_note_id INTEGER NOT NULL REFERENCES debit_notes(debit_note_id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(product_id),
    grn_item_id INTEGER REFERENCES grn_items(grn_item_id), -- Trace back to exact batch/received item
    quantity_returned NUMERIC(12, 3) NOT NULL CHECK (quantity_returned > 0),
    reason_code VARCHAR(50) NOT NULL CHECK (reason_code IN ('damaged', 'expired', 'short_expiry', 'wrong_item', 'defective')),
    reason_description TEXT, 
    unit_cost NUMERIC(12, 2) NOT NULL,
    subtotal NUMERIC(12, 2) GENERATED ALWAYS AS (quantity_returned * unit_cost) STORED,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE sales_orders (
    sales_order_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    order_number VARCHAR(100) UNIQUE NOT NULL,            -- e.g., SO-2026-001
    customer_id INTEGER NOT NULL REFERENCES customers(customer_id),
    quote_id INTEGER REFERENCES quotes(quote_id),         -- Optional: Links to quote if exists
    customer_lpo_number VARCHAR(100),                     -- Capture client's LPO if B2B
    sales_channel VARCHAR(20) NOT NULL CHECK (sales_channel IN ('pos', 'ecommerce', 'b2b')),
    sold_by INTEGER REFERENCES users(user_id),            -- Cashier/Rep
    currency_id INTEGER NOT NULL REFERENCES currencies(currency_id),
    subtotal NUMERIC(12, 2) DEFAULT 0,
    discount_amount NUMERIC(12, 2) DEFAULT 0,
    tax_amount NUMERIC(12, 2) DEFAULT 0,
    total_amount NUMERIC(12, 2) DEFAULT 0,
    shipping_address_id INTEGER REFERENCES customer_addresses(address_id),
    status VARCHAR(20) DEFAULT 'pending' CHECK (
        status IN ('pending', 'processing', 'shipped', 'delivered', 'completed', 'cancelled')
    ),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE sales_order_items (
    sales_order_item_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sales_order_id INTEGER NOT NULL REFERENCES sales_orders(sales_order_id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(product_id),
    quantity NUMERIC(12, 3) NOT NULL CHECK (quantity > 0), 
    unit_price NUMERIC(12, 2) NOT NULL,
    discount NUMERIC(12, 2) DEFAULT 0,
    subtotal NUMERIC(12, 2) GENERATED ALWAYS AS ((quantity * unit_price) - discount) STORED,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
SELECT * FROM grns;
SELECT * FROM grn_items;
SELECT * FROM product_batches;
SELECT * FROM products;
SELECT * FROM debit_note_items;