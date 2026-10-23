-- Entrar com Google: guardamos só o identificador da conta Google (campo "sub"),
-- que não muda mesmo se a pessoa trocar o e-mail. Não guardamos foto nem token.
ALTER TABLE usuarios
  ADD COLUMN google_sub VARCHAR(64) NULL COMMENT 'Conta Google vinculada (sub do ID token)' AFTER email,
  ADD UNIQUE KEY uq_usuarios_google (google_sub);
