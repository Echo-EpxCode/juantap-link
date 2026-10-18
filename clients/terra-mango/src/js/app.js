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

function renderProducts() {
    const grid = document.querySelector("[data-products-grid]");

    if (!grid || !Array.isArray(content.products.items)) {
        return;
    }

    grid.replaceChildren();
    content.products.items.forEach((product) => {
        const card = document.createElement("article");
        card.className = "product-card";

        const media = document.createElement("div");
        media.className = "product-card__media";

        if (product.image) {
            const image = document.createElement("img");
            image.src = product.image;
            image.alt = product.title || "";
            image.loading = "lazy";
            media.append(image);
        } else {
            const placeholder = document.createElement("div");
            placeholder.className = "product-card__placeholder";
            placeholder.setAttribute("aria-hidden", "true");

            const icon = document.createElement("i");
            icon.className = `bi ${product.icon || "bi-image"}`;
            placeholder.append(icon);
            media.append(placeholder);
        }

        const details = document.createElement("div");
        details.className = "product-card__details";

        const title = document.createElement("h3");
        title.textContent = product.title;

        const description = document.createElement("p");
        description.textContent = product.description;

        const footer = document.createElement("div");
        footer.className = "product-card__footer";

        const price = document.createElement("span");
        price.textContent = product.price || "";

        const arrow = document.createElement("i");
        arrow.className = "bi bi-arrow-right";
        arrow.setAttribute("aria-hidden", "true");

        footer.append(price, arrow);
        details.append(title, description, footer);
        card.append(media, details);
        grid.append(card);
    });
}

function renderDigitalStampCard() {
    const grid = document.querySelector("[data-stamp-grid]");
    const progress = document.querySelector("[data-stamp-progress]");
    const progressBar = document.querySelector("[data-stamp-progress-bar]");
    const total = Math.max(1, Math.floor(Number(content.digitalStampCard.stampsRequired) || 1));
    const earned = Math.min(total, Math.max(0, Math.floor(Number(content.digitalStampCard.stampsEarned) || 0)));

    if (grid) {
        grid.replaceChildren();
        for (let index = 0; index < total; index += 1) {
            const slot = document.createElement("li");
            slot.className = `stamp-slot${index < earned ? " is-earned" : ""}`;
            slot.setAttribute("aria-label", `Stamp ${index + 1}: ${index < earned ? "earned" : "not yet earned"}`);

            const icon = document.createElement("i");
            icon.className = `bi ${index < earned ? "bi-cup-hot-fill" : "bi-plus-lg"}`;
            icon.setAttribute("aria-hidden", "true");
            slot.append(icon);
            grid.append(slot);
        }
    }

    setText("[data-stamp-count]", `${earned} of ${total} stamps`);
    setText("[data-stamp-next-reward]", earned === total
        ? "Your sample card is full!"
        : `Collect ${total - earned} more ${total - earned === 1 ? "stamp" : "stamps"} to unlock a free mango milk tea.`);

    if (progress) {
        progress.setAttribute("aria-valuemax", String(total));
        progress.setAttribute("aria-valuenow", String(earned));
        progress.setAttribute("aria-valuetext", `${earned} of ${total} sample stamps earned`);
    }

    if (progressBar) {
        progressBar.style.width = `${(earned / total) * 100}%`;
    }
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
    setText("[data-products-eyebrow]", content.products.eyebrow);
    setText("[data-products-title-main]", content.products.title);
    setText("[data-products-title-highlight]", content.products.highlight);
    setText("[data-products-description]", content.products.description);
    setText("[data-stamp-eyebrow]", content.digitalStampCard.eyebrow);
    setText("[data-stamp-title-main]", content.digitalStampCard.title);
    setText("[data-stamp-title-highlight]", content.digitalStampCard.highlight);
    setText("[data-stamp-description]", content.digitalStampCard.description);
    setText("[data-stamp-card-label]", content.digitalStampCard.cardLabel);
    setText("[data-stamp-member-name]", content.digitalStampCard.memberName);
    setText("[data-stamp-card-number]", content.digitalStampCard.cardNumber);
    setText("[data-stamp-reward-title]", content.digitalStampCard.rewardTitle);
    setText("[data-stamp-next-reward]", content.digitalStampCard.rewardDescription);
    setText("[data-stamp-action-label]", content.digitalStampCard.buttonLabel);
    setText("[data-stamp-preview-note]", content.digitalStampCard.previewNote);
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
    renderProducts();
    renderDigitalStampCard();
    renderOrderOptions();

    const stampAction = document.querySelector("[data-stamp-action]");
    if (stampAction) {
        stampAction.href = content.digitalStampCard.buttonUrl;
    }
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
