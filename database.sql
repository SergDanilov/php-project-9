CREATE TABLE urls (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURDATE()  
);
ALTER TABLE urls
ADD COLUMN response_code INT,
ADD COLUMN h1 VARCHAR(255),
ADD COLUMN title VARCHAR(255),
ADD COLUMN description TEXT,
ADD COLUMN last_check TIMESTAMP;

CREATE TABLE url_checks (
    id SERIAL PRIMARY KEY,
    url_id SERIAL REFERENCES urls(id) NOT NULL, 
    status_code INT,
    h1 VARCHAR(255),
    title VARCHAR(255),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP()  
);