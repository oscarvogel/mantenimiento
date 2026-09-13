"""Extract and vectorize the assistant state illustrations from the reference grids.

The PNGs remain local design references. This script creates transparent SVGs for
the product from the robot illustrations only, leaving labels and card chrome out.
It requires Pillow and vtracer on the workstation used to regenerate the assets.
"""

from collections import deque
from pathlib import Path
from tempfile import TemporaryDirectory

from PIL import Image
import vtracer


ROOT = Path(__file__).resolve().parents[1]
BRAND = ROOT / "assets" / "brand"
OUTPUT = BRAND / "chatbot"

FULL_SOURCE = BRAND / "ChatGPT Image 13 sept 2026, 13_51_31.png"
FAB_SOURCE = BRAND / "ChatGPT Image 13 sept 2026, 13_52_39.png"

FULL_STATES = {
    "thinking": (35, 145, 307, 493),
    "loading": (337, 145, 607, 493),
    "success": (634, 145, 903, 493),
    "error": (934, 145, 1203, 493),
    "offline": (1235, 145, 1505, 493),
}

FAB_CROPS = {
    "dark": {
        "thinking": (58, 145, 294, 385),
        "success": (334, 145, 569, 385),
        "error": (608, 145, 842, 385),
        "offline": (881, 145, 1116, 385),
        "loading": (1154, 145, 1391, 385),
    },
    "light": {
        "thinking": (60, 635, 294, 874),
        "success": (334, 635, 569, 874),
        "error": (607, 635, 842, 874),
        "offline": (882, 635, 1117, 874),
        "loading": (1154, 635, 1392, 874),
    },
}

STATE_ORDER = ("thinking", "loading", "success", "error", "offline")


def _background_color(image: Image.Image) -> tuple[int, int, int]:
    rgb = image.convert("RGB")
    points = (
        rgb.getpixel((0, 0)),
        rgb.getpixel((rgb.width - 1, 0)),
        rgb.getpixel((0, rgb.height - 1)),
        rgb.getpixel((rgb.width - 1, rgb.height - 1)),
    )
    return tuple(round(sum(point[index] for point in points) / len(points)) for index in range(3))


def _remove_connected_background(image: Image.Image, tolerance: int) -> Image.Image:
    """Make only the card background transparent, preserving enclosed white details."""
    result = image.convert("RGBA")
    rgb = result.convert("RGB")
    background = _background_color(result)
    pixels = rgb.load()
    visited: set[tuple[int, int]] = set()
    queue: deque[tuple[int, int]] = deque()

    def is_background(point: tuple[int, int]) -> bool:
        color = pixels[point]
        distance = sum((color[index] - background[index]) ** 2 for index in range(3)) ** 0.5
        return distance <= tolerance

    edge_points = []
    edge_points.extend((x, 0) for x in range(result.width))
    edge_points.extend((x, result.height - 1) for x in range(result.width))
    edge_points.extend((0, y) for y in range(1, result.height - 1))
    edge_points.extend((result.width - 1, y) for y in range(1, result.height - 1))

    for point in edge_points:
        if point not in visited and is_background(point):
            visited.add(point)
            queue.append(point)

    while queue:
        x, y = queue.popleft()
        for neighbour in ((x - 1, y), (x + 1, y), (x, y - 1), (x, y + 1)):
            nx, ny = neighbour
            if not (0 <= nx < result.width and 0 <= ny < result.height):
                continue
            if neighbour in visited or not is_background(neighbour):
                continue
            visited.add(neighbour)
            queue.append(neighbour)

    alpha = result.getchannel("A")
    for point in visited:
        alpha.putpixel(point, 0)
    result.putalpha(alpha)

    bbox = result.getchannel("A").getbbox()
    if bbox is None:
        raise RuntimeError("No se encontró una ilustración en el recorte")

    left, top, right, bottom = bbox
    padding = 6
    return result.crop((
        max(0, left - padding),
        max(0, top - padding),
        min(result.width, right + padding),
        min(result.height, bottom + padding),
    ))


def _vectorize(source: Image.Image, target: Path, tolerance: int, temporary: Path) -> None:
    prepared = _remove_connected_background(source, tolerance)
    input_path = temporary / "source.png"
    prepared.save(input_path)
    vtracer.convert_image_to_svg_py(
        str(input_path),
        str(target),
        colormode="color",
        hierarchical="stacked",
        mode="spline",
        filter_speckle=4,
        color_precision=8,
        layer_difference=16,
        corner_threshold=60,
        length_threshold=4,
        max_iterations=10,
        splice_threshold=45,
        path_precision=3,
    )


def main() -> None:
    if not FULL_SOURCE.exists() or not FAB_SOURCE.exists():
        raise FileNotFoundError("Faltan las dos grillas PNG dentro de assets/brand")

    OUTPUT.mkdir(parents=True, exist_ok=True)
    full_image = Image.open(FULL_SOURCE)
    fab_image = Image.open(FAB_SOURCE)

    with TemporaryDirectory(prefix="chatbot-state-assets-") as temporary_dir:
        temporary = Path(temporary_dir)

        for state in STATE_ORDER:
            full_crop = full_image.crop(FULL_STATES[state])
            for theme in ("light", "dark"):
                target = OUTPUT / f"robot-full-{state}-{theme}.svg"
                _vectorize(full_crop, target, tolerance=60, temporary=temporary)

        for theme, crops in FAB_CROPS.items():
            for state in STATE_ORDER:
                target = OUTPUT / f"robot-fab-{state}-{theme}.svg"
                _vectorize(fab_image.crop(crops[state]), target, tolerance=72, temporary=temporary)

    print(f"Generados {len(STATE_ORDER) * 4} SVGs en {OUTPUT}")


if __name__ == "__main__":
    main()
