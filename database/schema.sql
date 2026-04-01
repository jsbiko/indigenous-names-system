CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('contributor', 'editor', 'admin') NOT NULL DEFAULT 'contributor',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS name_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    meaning TEXT NOT NULL,
    ethnic_group VARCHAR(150) NOT NULL,
    region VARCHAR(150) DEFAULT NULL,
    gender ENUM('male', 'female', 'unisex', 'other') DEFAULT 'unisex',
    naming_context VARCHAR(255) DEFAULT NULL,
    cultural_explanation TEXT,
    sources TEXT,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_name_entries_user
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_id INT NOT NULL,
    reviewer_id INT DEFAULT NULL,
    action ENUM('approved', 'rejected', 'revision_requested') NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reviews_entry
        FOREIGN KEY (entry_id) REFERENCES name_entries(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_reviews_user
        FOREIGN KEY (reviewer_id) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS name_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_id INT NOT NULL UNIQUE,
    overview TEXT,
    linguistic_origin TEXT,
    cultural_significance TEXT,
    historical_context TEXT,
    variants TEXT,
    pronunciation VARCHAR(255),
    related_names TEXT,
    scholarly_notes TEXT,
    references_text TEXT,
    ai_summary TEXT,
    last_edited_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_name_profiles_entry
        FOREIGN KEY (entry_id) REFERENCES name_entries(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_name_profiles_editor
        FOREIGN KEY (last_edited_by) REFERENCES users(id)
        ON DELETE SET NULL
);

INSERT INTO name_entries
(name, meaning, ethnic_group, region, gender, naming_context, cultural_explanation, sources, status)
VALUES
('Akinyi', 'Born in the morning', 'Luo', 'Western Kenya', 'female', 'Birth time', 'A Luo name commonly associated with a girl born in the morning.', 'Community knowledge', 'approved'),
('Kwame', 'Born on Saturday', 'Akan', 'Ghana', 'male', 'Day-name tradition', 'An Akan day name traditionally given to boys born on Saturday.', 'Cultural reference', 'approved'),
('Thabo', 'Joy', 'Sotho', 'Southern Africa', 'male', 'Virtue naming', 'A name associated with happiness, joy, and positive aspiration.', 'General cultural source', 'approved');

INSERT INTO users (full_name, email, password_hash, role)
VALUES (
    'Admin User',
    'admin@system.com',
    '$2y$10$e0NRzVqH9M6G3zCz6Z8wFeu9Z7lZ1Q9zQ1p7p9Z1lZ1Q9zQ1p7p9Z',
    'admin'
);