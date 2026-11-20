// Speaker edit screen: pick the speaker's photo from the media library (see includes/class-speakers.php).
document.querySelectorAll('.pn-speaker-photo').forEach((box) => {
	const input = box.querySelector('input[name="pn_photo_id"]');
	const preview = box.querySelector('.pn-speaker-photo__preview');
	const remove = box.querySelector('.pn-speaker-photo__remove');
	let frame;

	box.querySelector('.pn-speaker-photo__choose').addEventListener('click', () => {
		frame ??= wp.media({
			title: box.dataset.title,
			button: { text: box.dataset.button },
			library: { type: 'image' },
			multiple: false,
		});
		frame.off('select').on('select', () => {
			const photo = frame.state().get('selection').first().toJSON();
			const img = document.createElement('img');
			img.src = photo.sizes?.thumbnail?.url ?? photo.url;
			img.alt = '';
			img.style.cssText = 'width:96px;height:96px;border-radius:50%;object-fit:cover';
			preview.replaceChildren(img);
			preview.hidden = false;
			remove.hidden = false;
			input.value = String(photo.id);
		});
		frame.open();
	});

	remove.addEventListener('click', () => {
		input.value = '';
		preview.replaceChildren();
		preview.hidden = true;
		remove.hidden = true;
	});
});
