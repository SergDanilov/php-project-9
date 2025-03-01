-- Создание таблицы urls
DROP TABLE IF EXISTS urls CASCADE;
CREATE TABLE urls (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    response_code INT,
    h1 VARCHAR(255),
    title VARCHAR(255),
    description TEXT,
    last_check TIMESTAMP
);

-- Создание таблицы url_checks
DROP TABLE IF EXISTS url_checks CASCADE;
CREATE TABLE url_checks (
    id SERIAL PRIMARY KEY,
    url_id INTEGER,
    status_code INT,
    h1 VARCHAR(255),
    title VARCHAR(255),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (url_id) REFERENCES urls(id)
);
