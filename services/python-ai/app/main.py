import os

from fastapi import FastAPI

SERVICE_NAME = "python-ai"
SERVICE_VERSION = os.getenv("SERVICE_VERSION", "0.1.0")

app = FastAPI(title="SIGOF-M python-ai", version=SERVICE_VERSION, docs_url=None, redoc_url=None, openapi_url=None)


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok", "service": SERVICE_NAME, "version": SERVICE_VERSION}
