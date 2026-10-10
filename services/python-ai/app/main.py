import os

from fastapi import FastAPI

SERVICE_NAME = "python-ai"
SERVICE_VERSION = os.getenv("SERVICE_VERSION", "0.1.0")
# Marca vem do ambiente (APP_NAME no .env/compose); o default é o único literal (allowlist da guarda).
APP_NAME = os.getenv("APP_NAME") or "GOF"

app = FastAPI(title=f"{APP_NAME} {SERVICE_NAME}", version=SERVICE_VERSION, docs_url=None, redoc_url=None, openapi_url=None)


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok", "service": SERVICE_NAME, "version": SERVICE_VERSION}
