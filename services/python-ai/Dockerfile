# syntax=docker/dockerfile:1
# Contexto: services/python-ai
FROM python:3.12.15-slim AS base
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1
WORKDIR /srv

FROM base AS test
COPY requirements.txt requirements-dev.txt ./
RUN pip install -r requirements-dev.txt
COPY . .
CMD ["pytest", "-q"]

FROM base AS prod
COPY requirements.txt ./
RUN pip install -r requirements.txt \
    && useradd --system --uid 10001 --no-create-home appuser
COPY app ./app
USER appuser
EXPOSE 8000
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
