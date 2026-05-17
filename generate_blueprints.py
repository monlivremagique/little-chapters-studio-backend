#!/usr/bin/env python3
import json, os, shutil, copy

TEMPLATE_PATH = "resources/book-blueprints/espace-robot/master.json"
BLUEPRINTS_DIR = "resources/book-blueprints"

BOOKS = [
    {
        "slug": "espace-robot",
        "productCode": "BOOK_ESPACE-ROBOT",
        "theme": ["adventure", "fantasy", "discovery"],
        "titles": {
            "fr": "L'Aventure Enchantée de {child_name}",
            "en": "{child_name}'s Enchanted Adventure",
            "nl": "Het Betoverde Avontuur van {child_name}",
        },
        "coverTexts": {
            "fr": "Une aventure magique et enchantée",
            "en": "A magical enchanted adventure",
            "nl": "Een magisch betoverd avontuur",
        },
        "promise": "Plonge dans un monde enchanté où chaque page révèle une nouvelle merveille et une amitié qui change tout.",
        "positioning": "Album premium belge alliant magie, découverte et émotion, dans la tradition des grands contes d'aventure.",
    },
    {
        "slug": "super-heros",
        "productCode": "BOOK_SUPER-HEROS",
        "theme": ["heroism", "everyday", "bravery"],
        "titles": {
            "fr": "Mon Super-Héros, c'est {child_name}",
            "en": "My Superhero is {child_name}",
            "nl": "Mijn Superheld is {child_name}",
        },
        "coverTexts": {
            "fr": "Devient le héros de ta propre histoire",
            "en": "Become the hero of your own story",
            "nl": "Word de held van je eigen verhaal",
        },
        "promise": "Découvre que les vrais super-héros ne portent pas de cape, mais ont un cœur courageux et des rêves plein la tête.",
        "positioning": "Album premium belge célébrant l'héroïsme du quotidien, l'entraide et le courage intérieur de chaque enfant.",
    },
    {
        "slug": "bonne-nuit",
        "productCode": "BOOK_BONNE-NUIT",
        "theme": ["bedtime", "dreams", "comfort"],
        "titles": {
            "fr": "Bonne Nuit, Mon Trésor {child_name}",
            "en": "Good Night, My Treasure {child_name}",
            "nl": "Welterusten, Mijn Schat {child_name}",
        },
        "coverTexts": {
            "fr": "Des histoires douces pour s'endormir",
            "en": "Gentle bedtime stories",
            "nl": "Zachte verhalen om in te slapen",
        },
        "promise": "Une douce histoire du soir qui emmène {child_name} au pays des rêves, blotti dans les bras de ceux qui l'aiment.",
        "positioning": "Album premium belge dédié au rituel du coucher, mêlant tendresse, apaisement et imagination douce.",
    },
    {
        "slug": "animaux-foret",
        "productCode": "BOOK_ANIMAUX-FORET",
        "theme": ["animals", "nature", "friendship"],
        "titles": {
            "fr": "{child_name} et la Forêt des Animaux",
            "en": "{child_name} and the Animal Forest",
            "nl": "{child_name} en het Dierenbos",
        },
        "coverTexts": {
            "fr": "Une aventure enchantée dans la forêt",
            "en": "An enchanted adventure in the forest",
            "nl": "Een betoverd avontuur in het bos",
        },
        "promise": "Dans une forêt magique, {child_name} se lie d'amitié avec des animaux extraordinaires qui révèlent les secrets de la nature.",
        "positioning": "Album premium belge célébrant l'amitié avec les animaux et la beauté de la nature, dans la tradition des grands albums forestiers.",
    },
    {
        "slug": "dinosaures",
        "productCode": "BOOK_DINOSAURES",
        "theme": ["dinosaurs", "prehistoric", "adventure"],
        "titles": {
            "fr": "{child_name} au Pays des Dinosaures",
            "en": "{child_name} in the Land of Dinosaurs",
            "nl": "{child_name} in het Land van de Dinosaurussen",
        },
        "coverTexts": {
            "fr": "L'aventure préhistorique commence",
            "en": "The prehistoric adventure begins",
            "nl": "Het prehistorische avontuur begint",
        },
        "promise": "Un voyage extraordinaire au temps des dinosaures où {child_name} découvre que le courage et la curiosité sont les meilleurs outils.",
        "positioning": "Album premium belge alliant paléontologie, aventure et découverte, dans l'esprit des grandes expéditions scientifiques.",
    },
    {
        "slug": "cherche-trouve",
        "productCode": "BOOK_CHERCHE-TROUVE",
        "theme": ["search", "interactive", "discovery"],
        "titles": {
            "fr": "{child_name} Cherche et Trouve",
            "en": "{child_name} Search and Find",
            "nl": "{child_name} Zoek en Vind",
        },
        "coverTexts": {
            "fr": "Un livre-jeu personnalisé",
            "en": "A personalized game-book",
            "nl": "Een gepersonaliseerd spelboek",
        },
        "promise": "Un livre-jeu géant où chaque double-page cache des trésors à trouver. {child_name} explore un monde fourmillant de détails.",
        "positioning": "Album-jeu premium belge mêlant observation, exploration et personnalisation, pour des heures de découverte en famille.",
    },
    {
        "slug": "famille-amour",
        "productCode": "BOOK_FAMILLE-AMOUR",
        "theme": ["family", "love", "tenderness"],
        "titles": {
            "fr": "Ma Famille, Mon Trésor - {child_name}",
            "en": "My Family, My Treasure - {child_name}",
            "nl": "Mijn Familie, Mijn Schat - {child_name}",
        },
        "coverTexts": {
            "fr": "Les plus beaux moments en famille",
            "en": "The most beautiful family moments",
            "nl": "De mooiste familiemomenten",
        },
        "promise": "Un voyage tendre au cœur de la famille, où {child_name} redécouvre tous ces petits moments qui font les grands bonheurs.",
        "positioning": "Album premium belge célébrant les liens familiaux, la transmission et la tendresse au quotidien.",
    },
    {
        "slug": "voyage-etoiles",
        "productCode": "BOOK_VOYAGE-ETOILES",
        "theme": ["space", "science", "exploration"],
        "titles": {
            "fr": "Le Voyage des Étoiles de {child_name}",
            "en": "{child_name}'s Star Journey",
            "nl": "De Sterrenreis van {child_name}",
        },
        "coverTexts": {
            "fr": "Voyage vers les étoiles",
            "en": "Journey to the stars",
            "nl": "Reis naar de sterren",
        },
        "promise": "Pars pour un voyage interstellaire où {child_name} explore les mystères de l'univers et découvre que les étoiles brillent pour chacun de nous.",
        "positioning": "Album premium belge éveillant la curiosité scientifique et l'émerveillement cosmique, dans la tradition de l'exploration spatiale.",
    },
    {
        "slug": "bienvenue-bebe",
        "productCode": "BOOK_BIENVENUE-BEBE",
        "theme": ["baby", "welcome", "family"],
        "titles": {
            "fr": "Bienvenue Bébé {child_name}",
            "en": "Welcome Baby {child_name}",
            "nl": "Welkom Baby {child_name}",
        },
        "coverTexts": {
            "fr": "Accueille le nouveau membre de la famille",
            "en": "Welcome the new family member",
            "nl": "Verwelkom het nieuwe familielid",
        },
        "promise": "Un tendre album de naissance qui raconte l'arrivée de {child_name} et tout l'amour qui l'attend dans sa nouvelle famille.",
        "positioning": "Album de naissance premium belge, doux et personnalisé, pour accueillir bébé avec toute la tendresse du monde.",
    },
    {
        "slug": "anniversaire",
        "productCode": "BOOK_ANNIVERSAIRE",
        "theme": ["birthday", "celebration", "magic"],
        "titles": {
            "fr": "Mon Anniversaire Magique - {child_name}",
            "en": "My Magical Birthday - {child_name}",
            "nl": "Mijn Magische Verjaardag - {child_name}",
        },
        "coverTexts": {
            "fr": "Le plus beau des anniversaires",
            "en": "The most beautiful birthday",
            "nl": "De mooiste verjaardag",
        },
        "promise": "Le plus merveilleux des anniversaires commence quand {child_name} souffle ses bougies et que la magie opère.",
        "positioning": "Album anniversaire premium belge, festif et personnalisé, pour célébrer le jour le plus spécial de l'année.",
    },
]

with open(TEMPLATE_PATH, "r") as f:
    template = json.load(f)

def update_base_public_path(obj, slug):
    old_base = obj.get("assets", {}).get("basePublicPath", "")
    old_slug = "espace-robot"
    if old_base:
        obj["assets"]["basePublicPath"] = old_base.replace(old_slug, slug)
    defaults = obj.get("assets", {}).get("defaults", {})
    for key, val in defaults.items():
        defaults[key] = val.replace(old_slug, slug)
    return obj

def update_pages_text(obj, cover_texts):
    texts = {
        "fr": {
            "cover": cover_texts["fr"],
            "dedication": "Pour {child_name}, une histoire rien que pour toi",
            "summary": "{child_name} a vécu une aventure inoubliable pleine de découvertes.",
            "backCover": "L'aventure continue pour toi, {child_name} !",
        },
        "en": {
            "cover": cover_texts["en"],
            "dedication": "For {child_name}, a story just for you",
            "summary": "{child_name} had an unforgettable adventure full of discoveries.",
            "backCover": "The adventure continues for you, {child_name}!",
        },
        "nl": {
            "cover": cover_texts["nl"],
            "dedication": "Voor {child_name}, een verhaal helemaal voor jou",
            "summary": "{child_name} beleefde een onvergetelijk avontuur vol ontdekkingen.",
            "backCover": "Het avontuur gaat verder voor jou, {child_name}!",
        },
    }
    for locale in ["fr", "en", "nl"]:
        pages = obj["locales"][locale]["pages"]
        pages["cover"]["text_template"] = texts[locale]["cover"]
        pages["dedication"]["text_template"] = texts[locale]["dedication"]
        pages["summary"]["text_template"] = texts[locale]["summary"]
        pages["backCover"]["text_template"] = texts[locale]["backCover"]
    return obj

def update_pages_title(obj, titles):
    for locale in ["fr", "en", "nl"]:
        book = obj["locales"][locale]["book"]
        book["title_template"] = titles[locale]
        pages = obj["locales"][locale]["pages"]
        pages["cover"]["title_template"] = titles[locale]
    return obj

for book in BOOKS:
    slug = book["slug"]
    master = copy.deepcopy(template)
    master["metadata"]["bookId"] = slug
    master["metadata"]["slug"] = slug
    master["metadata"]["productCode"] = book["productCode"]
    master["metadata"]["theme"] = book["theme"]
    master["metadata"]["promise"] = book["promise"]
    master["metadata"]["editorialPositioning"] = book["positioning"]
    master = update_base_public_path(master, slug)
    master = update_pages_title(master, book["titles"])
    master = update_pages_text(master, book["coverTexts"])
    out_path = os.path.join(BLUEPRINTS_DIR, slug, "master.json")
    with open(out_path, "w") as f:
        json.dump(master, f, indent=4, ensure_ascii=False)
    print(f"Written: {out_path}")
