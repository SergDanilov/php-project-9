-- Создание таблицы urls
GRANT ALL PRIVILEGES ON TABLE urls TO analyzer_user;
DROP TABLE IF EXISTS urls;
CREATE TABLE urls (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Добавление столбцов в таблицу urls
ALTER TABLE urls
    ADD COLUMN response_code INT,
    ADD COLUMN h1 VARCHAR(255),
    ADD COLUMN title VARCHAR(255),
    ADD COLUMN description TEXT,
    ADD COLUMN last_check TIMESTAMP;

-- Создание таблицы url_checks
DROP TABLE IF EXISTS url_checks;
CREATE TABLE url_checks (
    id SERIAL PRIMARY KEY,
    url_id INTEGER REFERENCES urls(id) ON DELETE CASCADE, 
    status_code INT,
    h1 VARCHAR(255),
    title VARCHAR(255),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);