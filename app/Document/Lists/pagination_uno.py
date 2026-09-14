# Script UNO de pagination (phase R5).
#
# Interroge une instance LibreOffice en écoute UNO pour obtenir la page RÉELLE
# de chaque paragraphe d'un DOCX. Appelé par `PaginationCalculator`.
#
# Pourquoi cette approche (établie par mesure, voir .ai/rules/lists.md) :
#   - PHPWord ne pagine pas ;
#   - `pdftotext`, Ghostscript et `mutool` sont absents de la machine ;
#   - la conversion PDF → TXT par LibreOffice échoue ;
#   - lire les flux PDF à la main ne donne rien d'exploitable (texte encodé
#     par police, opérateurs TJ fragmentés) ;
#   - `XPageCursor.getPage()` lève une RuntimeException depuis Python (interface
#     non enregistrée côté UNO/Python).
#
# CE QUI FONCTIONNE : le curseur de VUE expose `getPosition().Y`, une position
# verticale absolue en 1/100 mm. La page se déduit par arithmétique :
#     page = floor(Y / hauteur_page) + 1
#
# Vérifié : sur un DOCX de 3 pages, les trois titres retombent sur les
# pages 1, 2 et 3, et `page_hauteur` vaut 29700 (297 mm — un A4 exact).

import json
import sys


def main() -> dict:
    docx = sys.argv[1]
    port = sys.argv[2] if len(sys.argv) > 2 else "20200"

    import uno
    from com.sun.star.beans import PropertyValue

    localContext = uno.getComponentContext()
    resolver = localContext.ServiceManager.createInstanceWithContext(
        "com.sun.star.bridge.UnoUrlResolver", localContext
    )

    ctx = resolver.resolve(
        f"uno:socket,host=127.0.0.1,port={port};urp;StarOffice.ComponentContext"
    )
    desktop = ctx.ServiceManager.createInstanceWithContext(
        "com.sun.star.frame.Desktop", ctx
    )

    # Chargement en mode CACHÉ (Hidden=True) : le seul mode qui ne fait pas
    # tomber le pont UNO. Le document n'est pas affiché mais la mise en page
    # est calculée, ce qui suffit.
    props = (
        PropertyValue("Hidden", 0, True, 0),
        PropertyValue("ReadOnly", 0, True, 0),
    )

    doc = desktop.loadComponentFromURL(uno.systemPathToFileUrl(docx), "_blank", 0, props)

    if doc is None:
        return {"error": "document_non_charge"}

    try:
        controleur = doc.getCurrentController()

        # Dimensions de page : `Height` est en 1/100 mm (29700 = 297 mm).
        # Sert de diviseur pour convertir une position verticale en numéro de page.
        page_height = 29700

        try:
            style = doc.StyleFamilies.getByName("PageStyles").getByIndex(0)
            page_height = int(style.Height)
        except Exception:  # noqa: BLE001
            # Repli sur A4 : anormal, mais ne doit pas faire échouer le calcul.
            pass

        # Total de pages, fourni par le contrôleur. C'est cette valeur qui
        # permet de vérifier que la pagination lue est cohérente.
        total_pages = 0

        try:
            total_pages = int(controleur.PageCount)
        except Exception:  # noqa: BLE001
            pass

        # Parcours des paragraphes : le curseur de VUE sait où il se trouve,
        # contrairement à un curseur de texte ordinaire.
        vue = controleur.getViewCursor()
        positions = []
        enum = doc.Text.createEnumeration()

        while enum.hasMoreElements():
            element = enum.nextElement()

            if not element.supportsService("com.sun.star.text.Paragraph"):
                continue

            texte = element.getString().strip()

            if not texte:
                continue

            vue.gotoRange(element.Start, False)
            offset_y = int(vue.getPosition().Y)

            positions.append(
                {
                    "page": (offset_y // page_height) + 1,
                    "offset_y": offset_y,
                    "text": texte,
                }
            )

        return {
            "total_pages": total_pages,
            "page_height": page_height,
            "positions": positions,
        }
    finally:
        # Fermer systématiquement : un document laissé ouvert garde le fichier
        # verrouillé sous Windows et fait échouer le nettoyage.
        try:
            doc.close(False)
        except Exception:  # noqa: BLE001
            pass


if __name__ == "__main__":
    try:
        # ensure_ascii : la console Windows corrompt les accents, et le JSON
        # serait alors illisible côté PHP.
        print(json.dumps(main(), ensure_ascii=True))
    except Exception as exc:  # noqa: BLE001
        print(json.dumps({"error": f"{type(exc).__name__}: {exc}"}))
