document.addEventListener("DOMContentLoaded", function () {
    const scrollContainer = document.querySelector('.filtros-portafolio-scroll');
    const btnLeft  = document.querySelector('.chevron-left');
    const btnRight = document.querySelector('.chevron-right');

    if (!scrollContainer || !btnLeft || !btnRight) return;

    btnLeft.addEventListener('click', () => {
        scrollContainer.scrollBy({ left: -150, behavior: 'smooth' });
    });

    btnRight.addEventListener('click', () => {
        scrollContainer.scrollBy({ left: 150, behavior: 'smooth' });
    });
});
