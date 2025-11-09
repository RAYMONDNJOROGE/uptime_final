USE uptime_hotspot;

INSERT INTO admins (username, password, email, is_active)
VALUES (
    'admin',
    '$2y$12$Bv7.cuqVJS6Gxbytzr1jHud/GkEgxBverHY0PEN8RSQQMUQTt8sUO',
    'admin@uptime.local',
    1
);

UPDATE admins
SET password = '$2y$12$Bv7.cuqVJS6Gxbytzr1jHud/GkEgxBverHY0PEN8RSQQMUQTt8sUO'
WHERE username = 'admin';

UPDATE admins
SET username = 'raymondnjoroge20@gmail.com'
WHERE username = 'admin';

UPDATE admins
SET email = 'raymondnjoroge20@gmail.com'
WHERE username = 'raymondnjoroge20@gmail.com';

USE uptime_hotspot;

-- Replace with your desired credentials
INSERT INTO admins (
    username,
    password,
    email,
    is_active,
    role,
    created_at
) VALUES (
    'superadmin',                                -- username
    '$2y$10$eW5X9YzQv1gEJZKzjK9uUuYxHf3VZLzK1aZx9dJZx9dJZx9dJZx9dJ', -- hashed password
    'raymondnjoroge20@gmail.com',               -- email
    1,                                           -- is_active
    'super',                                     -- role
    NOW()                                        -- created_at
);