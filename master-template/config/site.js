const site = {
    // LOADER ANIMATION
    // skeleton
    // logo
    // spinner
    // none
    loader: {
        type: "none",
        text: "Loading...",
        logo: "./assets/images/hero.jpeg",
        animation: "fade",
    },
    // =====================================================
    // BUSINESS INFORMATION
    // =====================================================

    name: "Business Name",

    tagline: "Your business tagline goes here.",

    description: "A short description about the business and what it offers.",

    // =====================================================
    // CONTACT INFORMATION
    // =====================================================

    phone: "+63 900 000 0000",

    email: "hello@example.com",

    address: "Business Address, Philippines",

    // =====================================================
    // SOCIAL MEDIA
    // =====================================================

    facebook: "#",

    instagram: "#",

    tiktok: "#",

    // =====================================================
    // WEBSITE
    // =====================================================

    url: "https://example.com",

    // Float button
    floatingActions: {
        enabled: true,

        items: [
            {
                id: "foodpanda",
                label: "Foodpanda",
                url: "https://www.facebook.com/",
                enabled: true,
                color: "#D70F64",
                icon: "",
                logo: "./assets/images/Foodpanda/Foodpanda_.png",
            },
            {
                id: "grabfood",
                label: "GrabFood",
                url: "https://www.facebook.com/",
                enabled: true,
                color: "#00A859",
                icon: "",
                logo: "./assets/images/Grab/Grab_Logo_6.png",
            },
            {
                id: "messenger",
                label: "Chat with us",
                url: "",
                enabled: false,
                color: "#1877F2",
                icon: "bi-chat-dots-fill",
                logo: "",
            },
        ],
    },
};

export default site;
