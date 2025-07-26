-- =========================
-- 📄 Modèle SQL découpages admin (MySQL compatible)
-- =========================

-- Table des types de division (avec support hiérarchique et historisation)
CREATE TABLE country_division_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(10) NOT NULL UNIQUE, -- Code court utilisé comme préfixe (ex: RG, CM, QT)
    level INT NOT NULL,               -- Profondeur hiérarchique (0 = pays)
    parent_type_id INT NULL,         -- Lien vers le type parent s'il y a lieu
    description TEXT,
    valid_from DATE DEFAULT CURRENT_DATE,
    valid_to DATE DEFAULT NULL,
    is_active BOOLEAN GENERATED ALWAYS AS (
        valid_from <= CURRENT_DATE AND (valid_to IS NULL OR valid_to > CURRENT_DATE)
    ) STORED,

    CONSTRAINT fk_type_parent FOREIGN KEY (parent_type_id) REFERENCES country_division_types(id),
    UNIQUE (level, name)
);

-- Table des divisions administratives
CREATE TABLE country_divisions (
    id VARCHAR(20) PRIMARY KEY, -- Ex: RG-ABCD12
    type_id INT NOT NULL,
    parent_id VARCHAR(20) NULL,
    name VARCHAR(255) NOT NULL,
    division_slug VARCHAR(255) NOT NULL,
    valid_from DATE DEFAULT CURRENT_DATE,
    valid_to DATE DEFAULT NULL,
    is_active BOOLEAN GENERATED ALWAYS AS (
        valid_from <= CURRENT_DATE AND (valid_to IS NULL OR valid_to > CURRENT_DATE)
    ) STORED,
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    geom GEOMETRY NULL,

    CONSTRAINT fk_type FOREIGN KEY (type_id) REFERENCES country_division_types(id),
    CONSTRAINT fk_parent FOREIGN KEY (parent_id) REFERENCES country_divisions(id)
);

-- Index pour recherche rapide
CREATE INDEX idx_division_slug ON country_divisions (division_slug);
CREATE INDEX idx_type_level ON country_division_types (level);
CREATE INDEX idx_type_code ON country_division_types (code);
CREATE INDEX idx_division_active ON country_divisions (is_active);
CREATE INDEX idx_type_active ON country_division_types (is_active);

-- Vues logiques filtrées sur les entrées actives
CREATE VIEW current_country_divisions AS
SELECT * FROM country_divisions WHERE is_active = TRUE;

CREATE VIEW current_country_division_types AS
SELECT * FROM country_division_types WHERE is_active = TRUE;