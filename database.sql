CREATE TABLE urls (
    id bigint PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    name varchar(255) UNIQUE,
    created_at varchar(255)
);

CREATE TABLE url_checks (
    id bigint PRIMARY KEY GENERATED ALWAYS AS IDENTITY, 
    url_id bigint, 
    status_code int, 
    h1 varchar(255), 
    title varchar(255), 
    description text, 
    created_at varchar(255),
);

ALTER TABLE public.url_checks
    ADD CONSTRAINT url_checks_url_id_foreign FOREIGN KEY (url_id) REFERENCES public.urls(id);