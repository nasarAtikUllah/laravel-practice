<?php

it('has auth registration page', function () {
    $response = $this->get('/auth registration');

    $response->assertStatus(200);
});
