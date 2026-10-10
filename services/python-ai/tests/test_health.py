from fastapi.testclient import TestClient

from app.main import app

client = TestClient(app)


def test_health_returns_ok_payload():
    response = client.get("/health")
    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ok"
    assert body["service"] == "python-ai"
    assert body["version"]


def test_unknown_route_is_404():
    assert client.get("/nope").status_code == 404


def test_title_comes_from_app_name(monkeypatch):
    import importlib

    import app.main as main

    monkeypatch.setenv("APP_NAME", "Frota X")
    try:
        assert importlib.reload(main).app.title == "Frota X python-ai"
    finally:
        monkeypatch.undo()
        importlib.reload(main)
