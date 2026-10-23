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

function safeExternalUrl(value) {
    if (typeof value !== "string" || value.trim() === "") {
        return null;
    }

    try {
        const url = new URL(value, window.location.href);
        return ["http:", "https:"].includes(url.protocol) && url.hostname ? url.href : null;
    } catch {
        return null;
    }
}

function iconClasses(icon) {
    return typeof icon === "string" && /^bi-[a-z0-9-]{1,60}$/.test(icon)
        ? ["bi", icon]
        : ["bi", "bi-link-45deg"];
}

function renderSocialCards(items = content.social.cards) {
    const grid = document.querySelector("[data-social-grid]");

    if (!grid) {
        return;
    }

    grid.replaceChildren();
    items.forEach((item) => {
        const href = safeExternalUrl(item.url);
        const card = document.createElement(href ? "a" : "article");
        const brand = typeof item.brand === "string" && /^[a-z-]{1,30}$/.test(item.brand)
            ? item.brand
            : "default";
        card.className = `social-card social-card--${brand}`;
        if (href) {
            card.href = href;
            card.target = "_blank";
            card.rel = "noopener noreferrer";
        } else {
            card.tabIndex = 0;
        }

        const icon = document.createElement("span");
        icon.className = "social-card__icon";
        icon.setAttribute("aria-hidden", "true");

        const iconElement = document.createElement(brand === "default" ? "i" : "span");
        if (brand === "default") {
            iconElement.classList.add(...iconClasses(item.icon));
        } else {
            iconElement.className = "social-card__mark";
            iconElement.textContent = brand === "facebook" ? "f" : brand === "tiktok" ? "T" : "G";
        }
        icon.append(iconElement);

        const title = document.createElement("h3");
        title.textContent = item.name || item.label || "Visit us online";

        const description = document.createElement("p");
        description.textContent = item.description || "Visit us online";

        card.append(icon, title, description);
        if (item.status) {
            const status = document.createElement("span");
            status.className = "social-card__status";
            status.textContent = item.status;
            card.append(status);
        }
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

function renderOrderOptions(links = []) {
    const grid = document.querySelector("[data-order-grid]");

    if (!grid) {
        return;
    }

    grid.replaceChildren();
    const publicLinks = links.filter((link) => link.type === "ordering" && safeExternalUrl(link.url));

    if (publicLinks.length > 0) {
        publicLinks.forEach((link) => {
            const item = document.createElement("a");
            item.className = "order-option";
            item.href = safeExternalUrl(link.url);
            item.target = "_blank";
            item.rel = "noopener noreferrer";

            const icon = document.createElement("i");
            icon.classList.add(...iconClasses(link.icon), "order-option__icon");
            icon.setAttribute("aria-hidden", "true");

            const details = document.createElement("div");
            const title = document.createElement("h3");
            title.textContent = link.label;
            const status = document.createElement("p");
            status.textContent = "Open ordering link";
            details.append(title, status);

            const arrow = document.createElement("i");
            arrow.className = "bi bi-arrow-up-right";
            arrow.setAttribute("aria-hidden", "true");

            item.append(icon, details, arrow);
            grid.append(item);
        });
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

function renderPublicBranches(branches = []) {
    const section = document.querySelector("[data-public-branches]");
    const list = document.querySelector("[data-branch-list]");
    if (!section || !list) {
        return;
    }

    list.replaceChildren();
    section.hidden = branches.length === 0;
    branches.forEach((branch) => {
        const item = document.createElement("article");
        item.className = "footer-branch";

        const title = document.createElement("h3");
        title.textContent = branch.name;
        item.append(title);

        if (branch.address) {
            const address = document.createElement("p");
            address.textContent = branch.address;
            item.append(address);
        }

        if (branch.phone) {
            const phoneValue = String(branch.phone);
            const phoneLink = document.createElement("a");
            phoneLink.href = `tel:${phoneValue.replace(/[^+0-9]/g, "")}`;
            phoneLink.textContent = phoneValue;
            item.append(phoneLink);
        }

        list.append(item);
    });
}

function renderFooterSocialLinks(links = []) {
    const container = document.querySelector("[data-footer-social]");
    if (!container) {
        return;
    }

    const publicLinks = links.filter((link) => ["social", "review"].includes(link.type) && safeExternalUrl(link.url));
    if (publicLinks.length === 0) {
        return;
    }

    container.replaceChildren();
    publicLinks.forEach((link) => {
        const anchor = document.createElement("a");
        anchor.href = safeExternalUrl(link.url);
        anchor.target = "_blank";
        anchor.rel = "noopener noreferrer";
        anchor.setAttribute("aria-label", link.label);

        const icon = document.createElement("i");
        icon.classList.add(...iconClasses(link.icon));
        icon.setAttribute("aria-hidden", "true");
        anchor.append(icon);
        container.append(anchor);
    });
}

function renderFloatingActions(links = []) {
    const root = document.createElement("nav");
    root.className = "floating-actions";
    root.setAttribute("aria-label", "Order with a delivery app");

    const publicLinks = links.filter((item) => item.type === "ordering" && safeExternalUrl(item.url));
    if (publicLinks.length > 0) {
        publicLinks.slice(0, 3).forEach((action) => {
            const link = document.createElement("a");
            link.className = "floating-actions__button";
            link.href = safeExternalUrl(action.url);
            link.target = "_blank";
            link.rel = "noopener noreferrer";
            link.title = `Order with ${action.label}`;
            link.setAttribute("aria-label", `Order with ${action.label}`);
            link.style.setProperty("--action-color", "#285a42");

            const icon = document.createElement("i");
            icon.classList.add(...iconClasses(action.icon));
            icon.setAttribute("aria-hidden", "true");
            link.append(icon);
            root.append(link);
        });

        document.body.append(root);
        return;
    }

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

function initializeCustomerAuthModal() {
    const triggers = document.querySelectorAll("[data-customer-modal-url]");
    if (triggers.length === 0) {
        return;
    }

    const dialog = document.createElement("dialog");
    dialog.className = "customer-auth-modal";
    dialog.setAttribute("aria-labelledby", "customer-auth-modal-title");

    const closeButton = document.createElement("button");
    closeButton.className = "customer-auth-modal__close";
    closeButton.type = "button";
    closeButton.setAttribute("aria-label", "Close customer account form");
    closeButton.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';

    const content = document.createElement("div");
    content.className = "customer-auth-modal__content";
    content.setAttribute("aria-live", "polite");
    dialog.append(closeButton, content);
    document.body.append(dialog);

    let activeRequest = 0;
    let triggerElement = null;

    const showMessage = (message) => {
        content.replaceChildren();
        const paragraph = document.createElement("p");
        paragraph.className = "customer-auth-modal__message";
        paragraph.textContent = message;
        content.append(paragraph);
    };

    const renderForm = (markup) => {
        const source = new DOMParser().parseFromString(markup, "text/html");
        const main = source.querySelector("main");
        if (!main) {
            throw new Error("Customer form content was unavailable.");
        }

        content.replaceChildren(...Array.from(main.childNodes, (node) => document.importNode(node, true)));
        const heading = content.querySelector("h1");
        if (heading) {
            heading.id = "customer-auth-modal-title";
        }
        content.querySelector('input:not([type="hidden"])')?.focus();
    };

    const openForm = async (url, trigger) => {
        const requestId = ++activeRequest;
        triggerElement = trigger;
        if (!dialog.open) {
            dialog.showModal();
        }
        showMessage("Loading customer account form…");

        try {
            const response = await fetch(url, { headers: { Accept: "text/html" }, credentials: "same-origin" });
            if (!response.ok) {
                throw new Error(`Customer form returned ${response.status}`);
            }
            if (new URL(response.url).pathname.endsWith("/customer/account.php")) {
                window.location.assign(response.url);
                return;
            }
            const markup = await response.text();
            if (requestId === activeRequest && dialog.open) {
                renderForm(markup);
            }
        } catch (error) {
            console.error("Customer account form could not load.", error);
            if (requestId === activeRequest && dialog.open) {
                showMessage("The customer account form could not load. Please try again.");
            }
        }
    };

    closeButton.addEventListener("click", () => dialog.close());
    dialog.addEventListener("click", (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });
    dialog.addEventListener("close", () => triggerElement?.focus());

    content.addEventListener("submit", async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        event.preventDefault();
        showMessage("Submitting…");

        try {
            const response = await fetch(form.action, {
                method: form.method || "POST",
                body: new FormData(form),
                credentials: "same-origin",
                headers: { Accept: "text/html" },
            });
            const accountPath = "/customer/account.php";
            if (new URL(response.url).pathname.endsWith(accountPath)) {
                window.location.assign(response.url);
                return;
            }
            renderForm(await response.text());
        } catch (error) {
            console.error("Customer account form could not submit.", error);
            showMessage("The form could not be submitted. Please try again.");
        }
    });

    content.addEventListener("click", (event) => {
        const link = event.target.closest('a[href*="/customer/"]');
        if (!link) {
            return;
        }
        const url = new URL(link.href, window.location.href);
        if (!/(?:login|register)\.php$/.test(url.pathname)) {
            return;
        }
        event.preventDefault();
        openForm(url.href, triggerElement);
    });

    triggers.forEach((trigger) => {
        trigger.addEventListener("click", (event) => {
            event.preventDefault();
            openForm(trigger.dataset.customerModalUrl, trigger);
        });
    });
}

function applyContent(publicData = null) {
    const business = publicData?.business;
    const businessName = business?.name || site.name;
    document.title = `${businessName} | Mango Milk Tea`;
    const descriptionMeta = document.querySelector('meta[name="description"]');
    if (descriptionMeta) {
        descriptionMeta.content = business?.description || business?.tagline || content.hero.description;
    }
    document.querySelectorAll("[data-site-name]").forEach((element) => {
        element.textContent = businessName;
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

    if (business?.tagline) {
        setText("[data-hero-eyebrow]", business.tagline);
    }
    if (business?.description) {
        setText("[data-hero-description]", business.description);
        setText("[data-footer-description]", business.description);
    }
    setText("[data-footer-copyright]", `© ${new Date().getFullYear()} ${businessName}. ${content.footer.copyright}`);

    const heroImage = document.querySelector("[data-hero-image]");
    if (heroImage) {
        heroImage.src = site.heroImage;
    }

    const links = Array.isArray(publicData?.links) ? publicData.links : [];
    renderProducts();
    renderDigitalStampCard();
    renderOrderOptions(links);
    renderPublicBranches(Array.isArray(publicData?.branches) ? publicData.branches : []);
    renderFooterSocialLinks(links);

    const stampAction = document.querySelector("[data-stamp-action]");
    if (stampAction) {
        stampAction.href = content.digitalStampCard.buttonUrl;
    }
}

async function loadPublicBusiness() {
    if (typeof site.businessSlug !== "string" || site.businessSlug.trim() === "") {
        return null;
    }

    try {
        const response = await fetch(`./public-business.php?slug=${encodeURIComponent(site.businessSlug)}`, {
            headers: { Accept: "application/json" },
        });
        if (!response.ok) {
            throw new Error(`Public business data returned ${response.status}`);
        }

        const payload = await response.json();
        return payload && typeof payload.data === "object" ? payload.data : null;
    } catch (error) {
        console.error("Public business data could not be loaded; using the local page content.", error);
        return null;
    }
}

async function renderWebsite() {
    if (!app) {
        return;
    }

    try {
        const [markup, publicData] = await Promise.all([
            Promise.all(sections.map(loadSection)),
            loadPublicBusiness(),
        ]);
        app.replaceChildren();
        markup.forEach((section) => app.insertAdjacentHTML("beforeend", section));

        applyContent(publicData);
        initializeMobileNavigation();
        initializeCustomerAuthModal();
        renderFloatingActions(publicData?.links || []);
    } catch (error) {
        app.innerHTML = '<p class="page-status" role="alert">Terra Mango could not load. Please refresh the page.</p>';
        console.error("Terra Mango page rendering failed:", error);
    }
}

renderWebsite();
