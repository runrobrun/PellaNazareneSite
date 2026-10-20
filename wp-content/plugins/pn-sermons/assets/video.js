document.addEventListener('click', (event) => {
	const box = event.target.closest('.pn-video');
	if (!box || box.querySelector('iframe')) {
		return;
	}
	const iframe = document.createElement('iframe');
	iframe.src = `https://www.youtube-nocookie.com/embed/${encodeURIComponent(box.dataset.id)}?autoplay=1&rel=0`;
	iframe.title = 'YouTube video player';
	iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
	iframe.allowFullscreen = true;
	box.replaceChildren(iframe);
});
