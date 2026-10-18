import content from "../../config/content.js";
import sections from "../../config/sections.js";
import site from "../../config/site.js";

const app = document.querySelector("#app");

async function loadSection(name) {
    const response = await fetch(`./src/sections/${name}.html`);

    if (!response.ok) {
        throw new Error(`Could not load section: ${name}`);
    }

    return response.text();
}

function setText(selector, value) {
    const element = document.querySelector(selector);

    if (element) {
        element.textContent = value;
    }
}

function renderSocialCards() {
    const grid = document.querySelector("[data-social-grid]");

    if (!grid) {
        return;
    }

    content.social.cards.forEach((item) => {
        const card = document.createElement("article");
        card.className = "social-card";
        card.tabIndex = 0;

        const icon = document.createElement("span");
        icon.className = "social-card__icon";
        icon.setAttribute("aria-hidden", "true");

        const iconElement = document.createElement("i");
        iconElement.className = `bi ${item.icon}`;
        icon.append(iconElement);

        const title = document.createElement("h3");
        title.textContent = item.name;

        const description = document.createElement("p");
        description.textContent = item.description;

        const status = document.createElement("span");
        status.className = "social-card__status";
        status.textContent = item.status;

        card.append(icon, title, description, status);
        grid.append(card);
    });
}

function renderOrderOptions() {
    const grid = document.querySelector("[data-order-grid]");

    if (!grid) {
        return;
    }

    site.orderingActions.forEach((action) => {
        const item = document.createElement("article");
        item.className = "order-option";
        item.id = `order-${action.id}`;

        const image = document.createElement("img");
        image.src = action.logo;
        image.alt = "";
        image.loading = "lazy";

        const details = document.createElement("div");
        const title = document.createElement("h3");
        title.textContent = action.label;
        const status = document.createElement("p");
        status.textContent = "Official ordering link coming soon";
        details.append(title, status);

        const icon = document.createElement("i");
        icon.className = "bi bi-arrow-down-right";
        icon.setAttribute("aria-hidden", "true");

        item.append(image, details, icon);
        grid.append(item);
    });
}

function renderFloatingActions() {
    const root = document.createElement("nav");
    root.className = "floating-actions";
    root.setAttribute("aria-label", "Order with a delivery app");

    site.orderingActions.forEach((action) => {
        const link = document.createElement("a");
        link.className = "floating-actions__button";
        link.href = action.href;
        link.title = `Order with ${action.label}`;
        link.setAttribute("aria-label", `Order with ${action.label}. Ordering link coming soon.`);
        link.style.setProperty("--action-color", action.color);

        const image = document.createElement("img");
        image.src = action.logo;
        image.alt = "";
        image.loading = "lazy";
        link.append(image);
        root.append(link);
    });

    document.body.append(root);
}

function initializeMobileNavigation() {
    const toggle = document.querySelector("[data-menu-toggle]");
    const navigation = document.querySelector("[data-mobile-navigation]");

    if (!toggle || !navigation) {
        return;
    }

    const closeMenu = () => {
        toggle.setAttribute("aria-expanded", "false");
        navigation.classList.remove("is-open");
    };

    toggle.addEventListener("click", () => {
        const isOpen = toggle.getAttribute("aria-expanded") === "true";
        toggle.setAttribute("aria-expanded", String(!isOpen));
        navigation.classList.toggle("is-open", !isOpen);
    });

    navigation.querySelectorAll("a").forEach((link) => {
        link.addEventListener("click", closeMenu);
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            closeMenu();
        }
    });
}

function applyContent() {
    document.title = `${site.name} | Mango Milk Tea`;
    document.querySelectorAll("[data-site-name]").forEach((element) => {
        element.textContent = site.name;
    });

    setText("[data-hero-eyebrow]", content.hero.eyebrow);
    setText("[data-hero-title]", content.hero.title);
    setText("[data-hero-highlight]", content.hero.highlight);
    setText("[data-hero-description]", content.hero.description);
    setText("[data-hero-primary-label]", content.hero.primaryLabel);
    setText("[data-hero-secondary-label]", content.hero.secondaryLabel);
    setText("[data-social-eyebrow]", content.social.eyebrow);
    setText("[data-social-title]", content.social.title);
    setText("[data-social-highlight]", content.social.highlight);
    setText("[data-social-description]", content.social.description);
    setText("[data-order-title]", content.social.orderTitle);
    setText("[data-order-description]", content.social.orderDescription);
    setText("[data-footer-description]", content.footer.description);
    setText("[data-footer-copyright]", `© ${new Date().getFullYear()} Terra Mango. ${content.footer.copyright}`);

    const heroImage = document.querySelector("[data-hero-image]");
    if (heroImage) {
        heroImage.src = site.heroImage;
    }

    renderSocialCards();
    renderOrderOptions();
}

async function renderWebsite() {
    if (!app) {
        return;
    }

    try {
        const markup = await Promise.all(sections.map(loadSection));
        app.replaceChildren();
        markup.forEach((section) => app.insertAdjacentHTML("beforeend", section));

        applyContent();
        initializeMobileNavigation();
        renderFloatingActions();
    } catch (error) {
        app.innerHTML = '<p class="page-status" role="alert">Terra Mango could not load. Please refresh the page.</p>';
        console.error("Terra Mango page rendering failed:", error);
    }
}

renderWebsite();
