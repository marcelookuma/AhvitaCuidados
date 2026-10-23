-- Celular confirmado por código (SMS/WhatsApp) ou só informado como contato.
-- Sem envio por celular ligado (Twilio), quem troca o celular em "Meus dados" o
-- salva sem código: fica telefone_confirmado = 0 e NUNCA serve para entrar na conta
-- (um número digitado errado não pode abrir os dados de outra pessoa).
-- Os celulares que já existem vieram do login por código: contam como confirmados.
ALTER TABLE usuarios
  ADD COLUMN telefone_confirmado TINYINT(1) NOT NULL DEFAULT 1 AFTER telefone;
