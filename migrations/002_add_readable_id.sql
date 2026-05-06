ALTER TABLE documents ADD COLUMN readable_id TEXT DEFAULT NULL;
CREATE UNIQUE INDEX idx_documents_readable_id ON documents (readable_id);