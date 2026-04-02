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
SELECT 'Admin User', 'admin@system.com', '$2y$10$e0NRzVqH9M6G3zCz6Z8wFeu9Z7lZ1Q9zQ1p7p9Z1lZ1Q9zQ1p7p9Z', 'admin'
WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE email = 'admin@system.com'
);

CREATE TABLE IF NOT EXISTS name_suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_id INT NOT NULL,
    suggested_by INT DEFAULT NULL,
    suggestion_type ENUM(
        'meaning',
        'cultural_explanation',
        'sources',
        'authority_profile',
        'general'
    ) NOT NULL DEFAULT 'general',
    proposed_meaning TEXT,
    proposed_naming_context VARCHAR(255),
    proposed_cultural_explanation TEXT,
    proposed_sources TEXT,
    proposed_overview TEXT,
    proposed_linguistic_origin TEXT,
    proposed_cultural_significance TEXT,
    proposed_historical_context TEXT,
    proposed_variants TEXT,
    proposed_pronunciation VARCHAR(255),
    proposed_related_names TEXT,
    proposed_scholarly_notes TEXT,
    proposed_references_text TEXT,
    contributor_notes TEXT,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    review_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_name_suggestions_entry
        FOREIGN KEY (entry_id) REFERENCES name_entries(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_name_suggestions_user
        FOREIGN KEY (suggested_by) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_name_suggestions_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS suggestion_merge_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    suggestion_id INT NOT NULL,
    entry_id INT NOT NULL,
    field_name VARCHAR(100) NOT NULL,
    target_table ENUM('name_entries', 'name_profiles') NOT NULL,
    old_value TEXT,
    new_value TEXT,
    merge_status ENUM('merged', 'skipped') NOT NULL,
    merged_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_merge_logs_suggestion
        FOREIGN KEY (suggestion_id) REFERENCES name_suggestions(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_merge_logs_entry
        FOREIGN KEY (entry_id) REFERENCES name_entries(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_merge_logs_user
        FOREIGN KEY (merged_by) REFERENCES users(id)
        ON DELETE SET NULL
);

ALTER TABLE suggestion_merge_logs
ADD COLUMN action_type ENUM('merge', 'rollback') NOT NULL DEFAULT 'merge' AFTER merge_status;

ALTER TABLE name_entries ADD UNIQUE (name, ethnic_group);

CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE user_role_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    changed_by INT NOT NULL,
    old_role VARCHAR(50),
    new_role VARCHAR(50),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE user_role_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    changed_by INT NOT NULL,
    old_role VARCHAR(50) NOT NULL,
    new_role VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_changed_by (changed_by),
    INDEX idx_created_at (created_at)
);